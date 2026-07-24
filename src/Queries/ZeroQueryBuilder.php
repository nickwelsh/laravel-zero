<?php

namespace NickWelsh\LaravelZero\Queries;

use BackedEnum;
use InvalidArgumentException;
use NickWelsh\LaravelZero\Contracts\ZeroSchemaRegistry;
use NickWelsh\LaravelZero\Filters\ZeroFilterDefinition;
use NickWelsh\LaravelZero\Filters\ZeroFilterOperator;
use NickWelsh\LaravelZero\Filters\ZeroFilterSchema;
use NickWelsh\LaravelZero\Filters\ZeroFilterValidator;
use NickWelsh\LaravelZero\Schema\ZeroModelSchema;
use NickWelsh\LaravelZero\Schema\ZeroRelationshipSchema;
use Stringable;

/**
 * @phpstan-type ZeroSimpleCondition array{
 *     type: 'simple',
 *     op: string,
 *     left: array{type: 'column', name: string},
 *     right: array{type: 'literal', value: mixed}
 * }
 * @phpstan-type ZeroExistsCondition array{type: 'exists', relationship: ZeroRelationshipSchema, builder: self}
 * @phpstan-type ZeroGroupCondition array{type: 'and'|'or', conditions: non-empty-list<array<string, mixed>>}
 * @phpstan-type ZeroCondition ZeroSimpleCondition|ZeroExistsCondition|ZeroGroupCondition
 */
final class ZeroQueryBuilder
{
    /** @var list<ZeroCondition> */
    private array $conditions = [];

    /** @var list<array{0: string, 1: string}> */
    private array $ordering = [];

    /** @var list<array{relationship: ZeroRelationshipSchema, builder: self}> */
    private array $related = [];

    private ?int $limitValue = null;

    private ?string $alias = null;

    /** @param class-string $modelClass */
    public function __construct(private readonly ZeroSchemaRegistry $registry, private readonly string $modelClass) {}

    public function where(string|ZeroQueryColumn $column, mixed $operatorOrValue, mixed $value = null): self
    {
        if (func_num_args() === 2) {
            $operator = '=';
            $value = $operatorOrValue;
        } else {
            if (! is_scalar($operatorOrValue) && $operatorOrValue !== null && ! $operatorOrValue instanceof Stringable) {
                throw new InvalidArgumentException('Unsupported Zero operator ['.get_debug_type($operatorOrValue).'].');
            }
            $operator = strtoupper((string) $operatorOrValue);
        }
        $allowed = ['=', '!=', '<', '>', '<=', '>=', 'LIKE', 'NOT LIKE', 'ILIKE', 'NOT ILIKE'];

        if (! in_array($operator, $allowed, true)) {
            throw new InvalidArgumentException("Unsupported Zero operator [{$operator}].");
        }

        if ($value === null) {
            if (! in_array($operator, ['=', '!='], true)) {
                throw new InvalidArgumentException("Zero null comparisons only support [=] and [!=], got [{$operator}].");
            }
            $operator = $operator === '!=' ? 'IS NOT' : 'IS';
        }

        return $this->condition($column, $operator, $value);
    }

    /** @param list<mixed> $values */
    public function whereIn(string|ZeroQueryColumn $column, array $values): self
    {
        return $this->condition($column, 'IN', $values);
    }

    /** @param list<mixed> $values */
    public function whereNotIn(string|ZeroQueryColumn $column, array $values): self
    {
        return $this->condition($column, 'NOT IN', $values);
    }

    public function whereNull(string|ZeroQueryColumn $column): self
    {
        return $this->condition($column, 'IS', null);
    }

    public function whereNotNull(string|ZeroQueryColumn $column): self
    {
        return $this->condition($column, 'IS NOT', null);
    }

    /** @param null|callable(self): mixed $callback */
    public function whereExists(string $relationship, ?callable $callback = null): self
    {
        $relationship = $this->schema()->relationship($relationship);
        $builder = $this->relationshipBuilder($relationship, 'zsubq_'.$relationship->name, $callback);
        $this->conditions[] = ['type' => 'exists', 'relationship' => $relationship, 'builder' => $builder];

        return $this;
    }

    /**
     * @param  array<string, mixed>  $filter
     * @param  class-string<ZeroFilterDefinition>  $definition
     */
    public function applyFilter(array $filter, string $definition): self
    {
        $schema = ZeroFilterDefinition::make($definition)->schema($this->registry);
        if ($schema->model !== $this->modelClass) {
            throw new InvalidArgumentException(
                "Filter definition [{$definition}] targets model [{$schema->model}], but the query builder targets [{$this->modelClass}].",
            );
        }

        $validated = (new ZeroFilterValidator($this->registry))->validate($filter, $definition);
        $this->conditions[] = $this->compileFilterNode($validated, $schema);

        return $this;
    }

    public function orderBy(string|ZeroQueryColumn $column, string|ZeroOrderDirection $direction = 'asc'): self
    {
        $direction = $direction instanceof ZeroOrderDirection ? $direction->value : strtolower($direction);
        if (! in_array($direction, ['asc', 'desc'], true)) {
            throw new InvalidArgumentException("Unsupported Zero order direction [{$direction}].");
        }
        $this->ordering[] = [$this->schema()->clientColumn($this->columnName($column)), $direction];

        return $this;
    }

    public function limit(int $limit): self
    {
        if ($limit < 1) {
            throw new InvalidArgumentException('Zero query limit must be positive.');
        }
        $this->limitValue = $limit;

        return $this;
    }

    public function one(): self
    {
        $this->limitValue = 1;

        return $this;
    }

    /** @param null|callable(self): mixed $callback */
    public function related(string $name, ?callable $callback = null): self
    {
        $relationship = $this->schema()->relationship($name);
        $builder = $this->relationshipBuilder($relationship, $relationship->name, $callback);
        $this->related[] = ['relationship' => $relationship, 'builder' => $builder];

        return $this;
    }

    /** @return array<string, mixed> */
    public function toAst(bool $serverNames = true): array
    {
        $schema = $this->schema();
        $mapColumn = fn (string $column): string => $serverNames ? array_search($column, $schema->columns, true) ?: $column : $column;
        $ast = ['table' => $serverNames ? $schema->serverTable : $schema->clientTable];

        if ($this->alias !== null) {
            $ast['alias'] = $this->alias;
        }

        if ($this->conditions !== []) {
            /** @var non-empty-list<ZeroCondition> $conditions */
            $conditions = $this->conditions;
            $ast['where'] = $this->conditionAst(
                $this->combineConditions('and', $conditions),
                $mapColumn,
                $serverNames,
            );
        }
        if ($this->related !== []) {
            $ast['related'] = array_map(
                fn (array $related): array => $this->relationshipAst($related['relationship'], $related['builder'], $serverNames),
                $this->related,
            );
        }
        if ($this->limitValue !== null) {
            $ast['limit'] = $this->limitValue;
        }
        if ($this->ordering !== []) {
            $ast['orderBy'] = array_map(fn (array $part): array => [$mapColumn($part[0]), $part[1]], $this->ordering);
        }

        return $ast;
    }

    private function condition(string|ZeroQueryColumn $column, string $operator, mixed $value): self
    {
        $this->conditions[] = $this->simpleCondition($column, $operator, $value);

        return $this;
    }

    /** @return ZeroSimpleCondition */
    private function simpleCondition(string|ZeroQueryColumn $column, string $operator, mixed $value): array
    {
        $value = $value instanceof BackedEnum ? $value->value : $value;
        if (is_array($value)) {
            $value = array_map(fn (mixed $item): mixed => $item instanceof BackedEnum ? $item->value : $item, $value);
        }

        return [
            'type' => 'simple',
            'op' => $operator,
            'left' => ['type' => 'column', 'name' => $this->schema()->clientColumn($this->columnName($column))],
            'right' => ['type' => 'literal', 'value' => $value],
        ];
    }

    /**
     * @param  array<string, mixed>  $node
     * @return ZeroCondition
     */
    private function compileFilterNode(array $node, ZeroFilterSchema $schema): array
    {
        return match ($node['type'] ?? null) {
            'condition' => $this->compileFilterCondition($node, $schema),
            'group' => $this->compileFilterGroup($node, $schema),
            'relationship' => $this->compileFilterRelationship($node, $schema),
            default => throw new InvalidArgumentException('Validated filter contains an unsupported node type.'),
        };
    }

    /**
     * @param  array<string, mixed>  $node
     * @return ZeroSimpleCondition
     */
    private function compileFilterCondition(array $node, ZeroFilterSchema $schema): array
    {
        $fieldId = $node['field'] ?? null;
        $operatorValue = $node['operator'] ?? null;
        if (! is_string($fieldId) || ! is_string($operatorValue)) {
            throw new InvalidArgumentException('Validated filter condition is missing its field or operator.');
        }

        $field = $schema->field($fieldId);
        $operator = ZeroFilterOperator::tryFrom($operatorValue);
        if ($operator === null) {
            throw new InvalidArgumentException("Validated filter contains an unsupported operator [{$operatorValue}].");
        }
        $value = $node['value'] ?? null;

        return match ($operator) {
            ZeroFilterOperator::Equals => $this->simpleCondition($field->column, '=', $value),
            ZeroFilterOperator::NotEquals => $this->simpleCondition($field->column, '!=', $value),
            ZeroFilterOperator::GreaterThan => $this->simpleCondition($field->column, '>', $value),
            ZeroFilterOperator::GreaterThanOrEqual => $this->simpleCondition($field->column, '>=', $value),
            ZeroFilterOperator::LessThan => $this->simpleCondition($field->column, '<', $value),
            ZeroFilterOperator::LessThanOrEqual => $this->simpleCondition($field->column, '<=', $value),
            ZeroFilterOperator::Contains => $this->simpleCondition($field->column, 'ILIKE', $this->likeValue($value, 'contains')),
            ZeroFilterOperator::NotContains => $this->simpleCondition($field->column, 'NOT ILIKE', $this->likeValue($value, 'contains')),
            ZeroFilterOperator::StartsWith => $this->simpleCondition($field->column, 'ILIKE', $this->likeValue($value, 'starts_with')),
            ZeroFilterOperator::EndsWith => $this->simpleCondition($field->column, 'ILIKE', $this->likeValue($value, 'ends_with')),
            ZeroFilterOperator::In => $this->simpleCondition($field->column, 'IN', $this->filterListValue($value)),
            ZeroFilterOperator::NotIn => $this->simpleCondition($field->column, 'NOT IN', $this->filterListValue($value)),
            ZeroFilterOperator::IsNull => $this->simpleCondition($field->column, 'IS', null),
            ZeroFilterOperator::IsNotNull => $this->simpleCondition($field->column, 'IS NOT', null),
        };
    }

    /**
     * @param  array<string, mixed>  $node
     * @return ZeroCondition
     */
    private function compileFilterGroup(array $node, ZeroFilterSchema $schema): array
    {
        $combinator = $node['combinator'] ?? null;
        $children = $node['children'] ?? null;
        if (! in_array($combinator, ['and', 'or'], true) || ! is_array($children) || ! array_is_list($children)) {
            throw new InvalidArgumentException('Validated filter group is malformed.');
        }

        $conditions = [];
        foreach ($children as $child) {
            if (! is_array($child)) {
                throw new InvalidArgumentException('Validated filter group contains a malformed child.');
            }
            /** @var array<string, mixed> $child */
            $conditions[] = $this->compileFilterNode($child, $schema);
        }
        if ($conditions === []) {
            throw new InvalidArgumentException('Validated filter groups cannot be empty.');
        }

        /** @var 'and'|'or' $combinator */
        /** @var non-empty-list<ZeroCondition> $conditions */
        return $this->combineConditions($combinator, $conditions);
    }

    /**
     * @param  array<string, mixed>  $node
     * @return ZeroExistsCondition
     */
    private function compileFilterRelationship(array $node, ZeroFilterSchema $schema): array
    {
        $relationshipId = $node['relationship'] ?? null;
        $quantifier = $node['quantifier'] ?? null;
        if (! is_string($relationshipId) || $quantifier !== 'some') {
            throw new InvalidArgumentException('Validated filter relationship is malformed or uses an unsupported quantifier.');
        }

        $descriptor = $schema->relationship($relationshipId);
        $relationship = $this->schema()->relationship($descriptor->relationship);
        $relatedSchema = ZeroFilterDefinition::make($descriptor->definition)->schema($this->registry);
        $builder = $this->relationshipBuilder($relationship, 'zsubq_'.$relationship->name, null);

        if (array_key_exists('filter', $node)) {
            $filter = $node['filter'];
            if (! is_array($filter)) {
                throw new InvalidArgumentException('Validated relationship filter is malformed.');
            }
            /** @var array<string, mixed> $filter */
            $builder->conditions[] = $builder->compileFilterNode($filter, $relatedSchema);
        }

        return ['type' => 'exists', 'relationship' => $relationship, 'builder' => $builder];
    }

    /**
     * @param  'and'|'or'  $combinator
     * @param  non-empty-list<ZeroCondition>  $conditions
     * @return ZeroCondition
     */
    private function combineConditions(string $combinator, array $conditions): array
    {
        $flattened = [];
        foreach ($conditions as $condition) {
            if ($condition['type'] === $combinator) {
                foreach ($condition['conditions'] as $child) {
                    /** @var ZeroCondition $child */
                    $flattened[] = $child;
                }
            } else {
                $flattened[] = $condition;
            }
        }

        /** @var non-empty-list<ZeroCondition> $flattened */
        if (count($flattened) === 1) {
            return $flattened[0];
        }

        return ['type' => $combinator, 'conditions' => $flattened];
    }

    /**
     * @param  ZeroCondition  $condition
     * @param  callable(string): string  $mapColumn
     * @return array<string, mixed>
     */
    private function conditionAst(array $condition, callable $mapColumn, bool $serverNames): array
    {
        if ($condition['type'] === 'simple') {
            $condition['left']['name'] = $mapColumn($condition['left']['name']);

            return $condition;
        }

        if ($condition['type'] === 'exists') {
            return [
                'type' => 'correlatedSubquery',
                'related' => $this->relationshipAst($condition['relationship'], $condition['builder'], $serverNames),
                'op' => 'EXISTS',
            ];
        }

        $children = [];
        foreach ($condition['conditions'] as $child) {
            /** @var ZeroCondition $child */
            $children[] = $this->conditionAst($child, $mapColumn, $serverNames);
        }

        return ['type' => $condition['type'], 'conditions' => $children];
    }

    /** @return list<mixed> */
    private function filterListValue(mixed $value): array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            throw new InvalidArgumentException('Validated in filter value must be a list.');
        }

        return $value;
    }

    /** @param 'contains'|'starts_with'|'ends_with' $mode */
    private function likeValue(mixed $value, string $mode): string
    {
        if (! is_string($value)) {
            throw new InvalidArgumentException('Validated pattern filter value must be a string.');
        }

        $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);

        return match ($mode) {
            'contains' => '%'.$escaped.'%',
            'starts_with' => $escaped.'%',
            'ends_with' => '%'.$escaped,
        };
    }

    /** @param null|callable(self): mixed $callback */
    private function relationshipBuilder(ZeroRelationshipSchema $relationship, string $alias, ?callable $callback): self
    {
        /** @var class-string $relatedModel */
        $relatedModel = $relationship->relatedModel;
        $builder = new self($this->registry, $relatedModel);
        $builder->alias = $alias;
        if ($callback !== null) {
            $callback($builder);
        }

        return $builder;
    }

    /** @return array<string, mixed> */
    private function relationshipAst(ZeroRelationshipSchema $relationship, self $builder, bool $serverNames): array
    {
        /** @var non-empty-list<string> $parentColumns */
        $parentColumns = $relationship->parentColumns;
        /** @var non-empty-list<string> $childColumns */
        $childColumns = $relationship->childColumns;

        return [
            'system' => 'client',
            'correlation' => [
                'parentField' => $serverNames ? $parentColumns : array_map($this->schema()->clientColumn(...), $parentColumns),
                'childField' => $serverNames ? $childColumns : array_map($builder->schema()->clientColumn(...), $childColumns),
            ],
            'subquery' => $builder->toAst($serverNames),
        ];
    }

    private function columnName(string|ZeroQueryColumn $column): string
    {
        $column = $column instanceof ZeroQueryColumn ? $column->value : $column;
        if (! is_string($column)) {
            throw new InvalidArgumentException('Zero query column enums must be string-backed.');
        }

        return $column;
    }

    private function schema(): ZeroModelSchema
    {
        return $this->registry->model($this->modelClass);
    }
}
