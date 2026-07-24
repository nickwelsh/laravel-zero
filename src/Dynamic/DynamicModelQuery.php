<?php

namespace NickWelsh\LaravelZero\Dynamic;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Database\Eloquent\Relations\Relation;
use InvalidArgumentException;
use NickWelsh\EloquentZero\Support\MorphRelationship;
use NickWelsh\LaravelZero\Contracts\ZeroSchemaRegistry;
use NickWelsh\LaravelZero\Queries\AllowedFilter;
use NickWelsh\LaravelZero\Queries\ZeroQueryBuilder;
use ReflectionMethod;
use RuntimeException;

final readonly class DynamicModelQuery
{
    /** @param class-string<Model> $modelClass */
    public function __construct(
        private string $modelClass,
        public string $name,
        private ZeroSchemaRegistry $schemas,
        private EloquentScopeApplicator $scopes,
    ) {}

    /** @return class-string<Model> */
    public function modelClass(): string
    {
        return $this->modelClass;
    }

    public function definition(): ZeroQueryBuilder
    {
        $model = new $this->modelClass;
        $builder = (new ReflectionMethod($model, 'zeroQueryBuilder'))->invoke($model);
        if (! $builder instanceof ZeroQueryBuilder) {
            throw new RuntimeException("[{$this->modelClass}::zeroQueryBuilder()] must return ".ZeroQueryBuilder::class.'.');
        }
        if ($builder->modelClass() !== $this->modelClass) {
            throw new RuntimeException("[{$this->modelClass}::zeroQueryBuilder()] targets [{$builder->modelClass()}].");
        }

        return $builder;
    }

    /** @param array<string, mixed> $args */
    public function build(array $args): ZeroQueryBuilder
    {
        $this->assertKeys($args, ['filters', 'includes', 'orderBy', 'limit', 'one']);
        $model = new $this->modelClass;
        $builder = $this->definition();
        $this->scopes->apply($builder, $model);

        $filters = $args['filters'] ?? [];
        if (! is_array($filters) || ! array_is_list($filters)) {
            throw new InvalidArgumentException('Dynamic Zero filters must be a list.');
        }
        foreach ($filters as $filter) {
            $this->applyFilter($builder, $filter);
        }

        $includes = $args['includes'] ?? [];
        if (! is_array($includes) || ! array_is_list($includes)) {
            throw new InvalidArgumentException('Dynamic Zero includes must be a list.');
        }
        $uniqueIncludes = [];
        foreach ($includes as $include) {
            if (! is_string($include)) {
                throw new InvalidArgumentException('Dynamic Zero include names must be strings.');
            }
            $uniqueIncludes[$include] = true;
        }
        foreach (array_keys($uniqueIncludes) as $include) {
            $this->applyInclude($builder, $model, $include);
        }

        $ordering = $args['orderBy'] ?? [];
        if (! is_array($ordering) || ! array_is_list($ordering)) {
            throw new InvalidArgumentException('Dynamic Zero ordering must be a list.');
        }
        foreach ($ordering as $order) {
            if (! is_array($order) || ! array_is_list($order) || count($order) !== 2 || ! is_string($order[0]) || ! is_string($order[1])) {
                throw new InvalidArgumentException('Each dynamic Zero ordering must be a [column, direction] tuple.');
            }
            if (! in_array($order[0], $builder->dynamicSorts(), true)) {
                throw new InvalidArgumentException("Dynamic Zero sort [{$order[0]}] is not allowed.");
            }
            $builder->orderBy($order[0], $order[1]);
        }

        if (array_key_exists('limit', $args)) {
            $limit = $args['limit'];
            $maximum = $this->maxLimit();
            if (! is_int($limit) || $limit < 1 || $limit > $maximum) {
                throw new InvalidArgumentException("Dynamic Zero limit must be between 1 and {$maximum}.");
            }
            $builder->limit($limit);
        }
        if (($args['one'] ?? false) === true) {
            $builder->one();
        } elseif (($args['one'] ?? false) !== false) {
            throw new InvalidArgumentException('Dynamic Zero one must be a boolean.');
        }

        return $builder;
    }

    /** @return array<string, Relation<Model, Model, mixed>> */
    public function includes(): array
    {
        $model = new $this->modelClass;
        $relations = [];
        foreach ($this->definition()->dynamicIncludes() as $name) {
            if (! method_exists($model, $name)) {
                throw new RuntimeException("Allowed Zero include [{$this->modelClass}::{$name}] does not exist.");
            }
            $relation = $model->{$name}();
            if (! $relation instanceof Relation) {
                throw new RuntimeException("Allowed Zero include [{$this->modelClass}::{$name}] is not an Eloquent relation.");
            }
            $relations[$name] = $relation;
        }

        return $relations;
    }

    private function applyFilter(ZeroQueryBuilder $builder, mixed $filter): void
    {
        if (! is_array($filter)) {
            throw new InvalidArgumentException('Each dynamic Zero filter must be an object.');
        }
        $this->assertKeys($filter, ['field', 'operator', 'value']);
        $field = $filter['field'] ?? null;
        $operator = strtoupper(is_string($filter['operator'] ?? null) ? $filter['operator'] : '=');
        if (! is_string($field)) {
            throw new InvalidArgumentException('Dynamic Zero filter field must be a string.');
        }
        $allowed = $builder->dynamicFilters()[$field] ?? null;
        if (! $allowed instanceof AllowedFilter || ! $allowed->allows($operator)) {
            throw new InvalidArgumentException("Dynamic Zero filter [{$field} {$operator}] is not allowed.");
        }
        $value = $filter['value'] ?? null;
        match ($operator) {
            'IN' => $builder->whereIn($field, $this->listValue($value)),
            'NOT IN' => $builder->whereNotIn($field, $this->listValue($value)),
            'IS' => $value === null ? $builder->whereNull($field) : $builder->where($field, $operator, $this->literalValue($value)),
            'IS NOT' => $value === null ? $builder->whereNotNull($field) : $builder->where($field, $operator, $this->literalValue($value)),
            'LIKE', 'NOT LIKE', 'ILIKE', 'NOT ILIKE' => $builder->where($field, $operator, $this->patternValue($value)),
            default => $builder->where($field, $operator, $this->literalValue($value)),
        };
    }

    private function applyInclude(ZeroQueryBuilder $builder, Model $model, string $include): void
    {
        if (! in_array($include, $builder->dynamicIncludes(), true)) {
            throw new InvalidArgumentException("Dynamic Zero include [{$include}] is not allowed.");
        }
        if (! method_exists($model, $include) || ! ($relation = $model->{$include}()) instanceof Relation) {
            throw new InvalidArgumentException("Dynamic Zero include [{$include}] is not an Eloquent relation.");
        }

        if ($relation instanceof MorphToMany) {
            $this->addMorphInclude($builder, $relation, $include);

            return;
        }

        $builder->related($include, fn (ZeroQueryBuilder $related) => $this->scopes->apply($related, $relation->getRelated()));
    }

    /** @param MorphToMany<Model, Model> $relation */
    private function addMorphInclude(ZeroQueryBuilder $builder, MorphToMany $relation, string $include): void
    {
        $related = new ZeroQueryBuilder($this->schemas, $relation->getRelated()::class);
        $this->scopes->apply($related, $relation->getRelated());
        $relatedAst = $related->toAst();
        $relatedAst['alias'] = MorphRelationship::related(new $this->modelClass, $include);
        $pivotAlias = MorphRelationship::pivot($include);

        $builder->addRawRelated([
            'system' => 'client',
            'correlation' => [
                'parentField' => [$relation->getParentKeyName()],
                'childField' => [$relation->getForeignPivotKeyName()],
            ],
            'subquery' => [
                'table' => $relation->getTable(),
                'alias' => $pivotAlias,
                'where' => [
                    'type' => 'simple',
                    'op' => '=',
                    'left' => ['type' => 'column', 'name' => $relation->getMorphType()],
                    'right' => ['type' => 'literal', 'value' => $relation->getMorphClass()],
                ],
                'related' => [[
                    'system' => 'client',
                    'correlation' => [
                        'parentField' => [$relation->getRelatedPivotKeyName()],
                        'childField' => [$relation->getRelatedKeyName()],
                    ],
                    'subquery' => $relatedAst,
                ]],
            ],
        ]);
    }

    /** @return list<mixed> */
    private function listValue(mixed $value): array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            throw new InvalidArgumentException('Dynamic Zero IN values must be a list.');
        }

        return array_map($this->literalValue(...), $value);
    }

    private function patternValue(mixed $value): string
    {
        if (! is_string($value)) {
            throw new InvalidArgumentException('Dynamic Zero pattern values must be strings.');
        }

        return $value;
    }

    private function literalValue(mixed $value): string|int|float|bool|null
    {
        if (! is_scalar($value) && $value !== null) {
            throw new InvalidArgumentException('Dynamic Zero values must be JSON scalar values.');
        }

        return $value;
    }

    /** @param array<mixed, mixed> $value
     * @param  list<string>  $allowed
     */
    private function assertKeys(array $value, array $allowed): void
    {
        $keys = array_keys($value);
        foreach ($keys as $key) {
            if (! is_string($key)) {
                throw new InvalidArgumentException('Dynamic Zero object keys must be strings.');
            }
        }
        $unknown = array_diff($keys, $allowed);
        if ($unknown !== []) {
            throw new InvalidArgumentException('Unknown dynamic Zero keys: '.implode(', ', $unknown).'.');
        }
    }

    private function maxLimit(): int
    {
        $limit = config('laravel-zero.dynamic_queries.max_limit', 100);
        if (! is_int($limit) || $limit < 1) {
            throw new RuntimeException('Dynamic Zero max_limit must be a positive integer.');
        }

        return $limit;
    }
}
