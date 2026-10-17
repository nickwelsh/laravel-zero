<?php

declare(strict_types=1);

namespace LaravelZero\Query;

use Closure;
use LaravelZero\Metadata\ModelMetadata;
use LaravelZero\Metadata\ModelRegistry;
use LaravelZero\Support\Input;
use LaravelZero\ZeroContext;
use LogicException;

/**
 * @phpstan-type Simple array{type: 'simple', op: string, left: array{type: 'column', name: string}, right: array{type: 'literal', value: mixed}}
 * @phpstan-type Correlated array{type: 'correlatedSubquery', related: array<string, mixed>, op: 'EXISTS'}
 * @phpstan-type Atom Simple|Correlated
 * @phpstan-type Condition array<string, mixed>
 * @phpstan-type Ast array{table: string, where?: Condition, orderBy?: list<array{string, 'asc'|'desc'}>, limit?: int, alias?: string, related?: list<array<string, mixed>>}
 * @phpstan-type Related array{correlation: array{parentField: list<string>, childField: list<string>}, subquery: Ast, system: 'client', hidden?: true}
 */
final readonly class QueryCompiler
{
    public function __construct(private ModelRegistry $registry) {}

    /**
     * @return Ast
     */
    public function compile(string $name, mixed $arguments, ZeroContext $context): array
    {
        $parts = explode('.', $name);
        if (count($parts) !== 2) {
            Input::fail('name', 'Unknown query.');
        }

        try {
            $model = $this->registry->get($parts[0]);
        } catch (LogicException) {
            Input::fail('name', 'Unknown query.');
        }

        $operation = $parts[1];
        if (! in_array($operation, $model->queries, true)) {
            Input::fail('name', 'Query is not enabled.');
        }

        $args = Input::object($arguments, 'args');
        Input::keys($args, $operation === 'one' ? ['id', 'include'] : ['filters', 'include', 'order', 'limit'], 'args');
        if ($operation === 'one') {
            if (! array_key_exists('id', $args)) {
                Input::fail('id', 'An id is required.');
            }

            Input::value($args['id'], $model->columns[$model->key], 'id');
        }

        return $this->build($model, $args, $context, $operation, 0);
    }

    /**
     * @param  array<string, mixed>  $args
     * @return Ast
     */
    private function build(ModelMetadata $model, array $args, ZeroContext $context, string $operation, int $depth): array
    {
        if ($depth > 8) {
            Input::fail('include', 'Includes exceed the maximum depth.');
        }

        $filters = Input::object($args['filters'] ?? [], 'filters');
        Input::keys($filters, array_keys($model->definition->filters), 'filters');
        $conditions = $this->visibility($model, $context, $depth);
        $trashed = $filters['trashed'] ?? 'without';
        if (! in_array($trashed, ['without', 'with', 'only'], true)) {
            Input::fail('filters.trashed', 'Expected without, with, or only.');
        }

        if ($model->deletedAt !== null && $trashed !== 'with') {
            $conditions[] = $this->comparison($model->deletedAt, $trashed === 'only' ? 'IS NOT' : 'IS', null);
        }

        if ($operation === 'one') {
            $conditions[] = $this->comparison($model->key, '=', $args['id']);
        }

        foreach ($filters as $name => $value) {
            $filter = $model->definition->filters[$name];
            if ($filter['kind'] === 'trashed') {
                continue;
            }

            $field = $filter['field'];
            if ($filter['kind'] === 'belongsTo') {
                $relation = $model->relations[$field];
                $target = $this->registry->get($relation['model']);
                Input::value($value, $target->columns[$target->key], "filters.{$name}");
                $subquery = $this->related($model, $field, [], $context, $depth + 1, true);
                $subquery['subquery']['where'] = $this->conjunction(array_merge(
                    isset($subquery['subquery']['where']) ? [$subquery['subquery']['where']] : [],
                    [$this->comparison($target->key, '=', $value)],
                ));
                $conditions[] = ['type' => 'correlatedSubquery', 'related' => $subquery, 'op' => 'EXISTS'];
            } else {
                $conditions[] = $this->filterCondition($model, $filter, $value, "filters.{$name}");
            }
        }

        $ast = ['table' => $model->table];
        if ($conditions !== []) {
            $ast['where'] = $this->conjunction($conditions);
        }

        $ast['orderBy'] = $this->order($model, $args['order'] ?? $model->definition->defaultOrder, array_key_exists('order', $args));
        if ($operation === 'one') {
            $ast['limit'] = 1;
        } elseif ($operation === 'all') {
            $ast['limit'] = $this->limit($args['limit'] ?? $model->definition->defaultLimit, $model->definition->maxLimit);
        } elseif (isset($args['limit'])) {
            $ast['limit'] = $this->limit($args['limit'], $model->definition->maxLimit);
        }

        $includes = Input::object($args['include'] ?? [], 'include');
        Input::keys($includes, $model->definition->includes, 'include');
        foreach ($includes as $name => $spec) {
            $ast['related'][] = $this->related($model, $name, Input::object($spec, "include.{$name}"), $context, $depth + 1);
        }

        return $ast;
    }

    /** @param array<string, mixed> $filter
     * @return Condition
     */
    private function filterCondition(ModelMetadata $model, array $filter, mixed $value, string $name): array
    {
        if ($filter['kind'] === 'group') {
            $members = $filter['members'];
            if (! is_array($members)) {
                throw new LogicException('Invalid filter group.');
            }

            $conditions = [];
            foreach ($members as $member) {
                $conditions[] = $this->filterCondition($model, Input::object($member, $name), $value, $name);
            }

            return ['type' => $filter['operator'], 'conditions' => $conditions];
        }

        if ($filter['kind'] === 'callback') {
            if ($filter['field'] === '') {
                if (! is_bool($value)) {
                    Input::fail($name, 'Expected a boolean.');
                }
            } else {
                $field = $filter['field'];
                if (! is_string($field)) {
                    throw new LogicException('Invalid callback field.');
                }

                Input::value($value, $model->columns[$field], $name);
            }

            return $this->callbackCondition($model, Input::object($filter['expression'], $name), $value);
        }

        $field = $filter['field'];
        $operator = $filter['operator'] ?? '=';
        if (! is_string($field) || ! is_string($operator)) {
            throw new LogicException('Invalid scalar filter.');
        }

        if ($operator === 'dynamic') {
            $input = Input::object($value, $name);
            Input::keys($input, ['operator', 'value'], $name);
            $operator = $input['operator'] ?? null;
            if (! is_string($operator) || ! in_array($operator, ['=', '!=', '<', '<=', '>', '>='], true) || ! array_key_exists('value', $input)) {
                Input::fail($name, 'Expected an allowed operator and value.');
            }

            $value = $input['value'];
        }

        Input::value($value, $model->columns[$field], $name);
        if (in_array($filter['kind'], ['partial', 'beginsWith', 'endsWith'], true)) {
            if (! is_string($value)) {
                Input::fail($name, 'Expected a string.');
            }

            $value = ($filter['kind'] === 'beginsWith' ? '' : '%').strtr($value, ['\\' => '\\\\', '%' => '\\%', '_' => '\\_']).($filter['kind'] === 'endsWith' ? '' : '%');
            $operator = 'ILIKE';
        } elseif ($value === null) {
            if (! in_array($operator, ['=', '!='], true)) {
                Input::fail($name, 'Null only supports equality operators.');
            }

            $operator = $operator === '!=' ? 'IS NOT' : 'IS';
        }

        return $this->comparison($field, $operator, $value);
    }

    /** @param array<string, mixed> $expression
     * @return Condition
     */
    private function callbackCondition(ModelMetadata $model, array $expression, mixed $value): array
    {
        if (($expression['type'] ?? null) === 'simple') {
            $left = Input::object($expression['left'], 'callback');
            $right = Input::object($expression['right'], 'callback');
            $field = $left['name'];
            $op = $expression['op'];
            if (! is_string($field) || ! is_string($op)) {
                throw new LogicException('Invalid callback expression.');
            }

            $operand = $right['type'] === 'filterValue' ? $value : ($right['value'] ?? null);

            return (new ZeroOperator($model, fn () => throw new LogicException('Unsupported callback relation.')))->cmp($field, $operand === null && $op === '=' ? 'IS' : $op, $operand);
        }

        $conditions = $expression['conditions'] ?? null;
        if (! is_array($conditions)) {
            throw new LogicException('Invalid callback group.');
        }

        $children = [];
        foreach ($conditions as $condition) {
            $children[] = $this->callbackCondition($model, Input::object($condition, 'callback'), $value);
        }

        return ['type' => $expression['type'], 'conditions' => $children];
    }

    /**
     * @param  array<string, mixed>  $args
     * @return Related
     */
    private function related(ModelMetadata $model, string $name, array $args, ZeroContext $context, int $depth, bool $predicate = false, ?Closure $refine = null): array
    {
        Input::keys($args, ['include', 'order', 'limit'], "include.{$name}");
        $relation = $model->relations[$name];
        $target = $this->registry->get($relation['model']);
        $path = $relation['path'];
        if (isset($args['limit']) && ($relation['cardinality'] === 'one' || count($path) > 1)) {
            Input::fail("include.{$name}.limit", 'Limits require a direct to-many relation.');
        }

        if (isset($args['order']) && count($path) > 1) {
            Input::fail("include.{$name}.order", 'Ordering junction includes is not supported by Zero.');
        }

        $ast = $this->build($target, $args, $context, 'related', $depth);
        if ($refine instanceof Closure) {
            $query = $this->scopeBuilder($target, $context, $depth);
            $refine($query);
            $ast['where'] = $this->conjunction([...isset($ast['where']) ? [$ast['where']] : [], $query->condition()]);
        }

        $ast['alias'] = $predicate ? 'zsubq_'.$name : $name;
        if (count($path) > 1) {
            // Zero cannot apply an explicit ordering through a junction include.
            unset($ast['orderBy']);
            $inner = ['correlation' => ['parentField' => $path[1]['source'], 'childField' => $path[1]['dest']], 'subquery' => $ast, 'system' => 'client'];
            $ast = ['table' => $path[0]['table'], 'alias' => $name, 'related' => [$inner]];
        }

        $result = ['correlation' => ['parentField' => $path[0]['source'], 'childField' => $path[0]['dest']], 'subquery' => $ast, 'system' => 'client'];
        if (count($path) > 1) {
            $result['hidden'] = true;
        }

        return $result;
    }

    /**
     * @return list<Condition>
     */
    private function visibility(ModelMetadata $model, ZeroContext $context, int $depth): array
    {
        $conditions = array_map(fn (array $rule): array => $this->comparison($rule['field'], $rule['op'], array_key_exists('context', $rule['value']) ? $context->get($rule['value']['context']) : $rule['value']['literal']), $model->authorization);
        foreach ($model->authorizationRelations as $relation) {
            $conditions[] = ['type' => 'correlatedSubquery', 'related' => $this->related($model, $relation, [], $context, $depth + 1, true), 'op' => 'EXISTS'];
        }

        foreach ($model->definition->serverScopes as $scope) {
            $query = $this->scopeBuilder($model, $context, $depth);
            $scope($query, $context);
            $conditions[] = $query->condition();
        }

        return $conditions;
    }

    private function scopeBuilder(ModelMetadata $model, ZeroContext $context, int $depth): ZQLBuilder
    {
        return new ZQLBuilder(new ZeroOperator($model, function (string $name, ?Closure $refine) use ($model, $context, $depth): array {
            if (! isset($model->relations[$name])) {
                throw new LogicException("Register scope relation {$model->name}.{$name} using enforceServerScope relations.");
            }

            return $this->related($model, $name, [], $context, $depth + 1, true, $refine);
        }));
    }

    /**
     * @return Simple
     */
    private function comparison(string $field, string $operator, mixed $value): array
    {
        return ['type' => 'simple', 'op' => $operator, 'left' => ['type' => 'column', 'name' => $field], 'right' => ['type' => 'literal', 'value' => $value]];
    }

    /**
     * @param  list<Condition>  $conditions
     * @return Condition
     */
    private function conjunction(array $conditions): array
    {
        return count($conditions) === 1 ? $conditions[0] : ['type' => 'and', 'conditions' => $conditions];
    }

    /**
     * @return list<array{string, 'asc'|'desc'}>
     */
    private function order(ModelMetadata $model, mixed $order, bool $client): array
    {
        if (! is_array($order) || ! array_is_list($order)) {
            Input::fail('order', 'Must be a list of field/direction pairs.');
        }

        $fields = [];
        foreach ($order as $pair) {
            if (! is_array($pair) || ! array_is_list($pair) || count($pair) !== 2 || ! is_string($pair[0]) || ! in_array($pair[1], ['asc', 'desc'], true) || ($client && ! in_array($pair[0], $model->definition->sorts, true)) || in_array($pair[0], $fields, true)) {
                Input::fail('order', 'Invalid or disallowed ordering.');
            }

            $fields[] = $pair[0];
        }

        if (! in_array($model->key, $fields, true)) {
            $order[] = [$model->key, 'asc'];
        }

        return $order;
    }

    private function limit(mixed $value, int $max): int
    {
        if (! is_int($value) || $value < 1 || $value > $max) {
            Input::fail('limit', "Must be an integer between 1 and {$max}.");
        }

        return $value;
    }
}
