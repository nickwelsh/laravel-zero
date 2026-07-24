<?php

namespace NickWelsh\LaravelZero\Dynamic;

use BackedEnum;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as BaseBuilder;
use NickWelsh\LaravelZero\Queries\ZeroQueryBuilder;
use RuntimeException;
use Stringable;

final class EloquentScopeApplicator
{
    /** @param class-string<Model>|Model $model */
    public function apply(ZeroQueryBuilder $zero, Model|string $model): void
    {
        $model = $model instanceof Model ? $model : new $model;
        $query = $model->newQuery()->applyScopes()->getQuery();
        $modelClass = $model::class;

        foreach (['joins', 'groups', 'havings', 'unions'] as $property) {
            if ($query->{$property} !== null) {
                throw new RuntimeException("Global scopes on [{$modelClass}] use unsupported query feature [{$property}].");
            }
        }
        if ($query->orders !== null || $query->limit !== null || $query->offset !== null || $query->distinct !== false || $query->columns !== null) {
            throw new RuntimeException("Global scopes on [{$modelClass}] may only add where constraints to dynamic Zero queries.");
        }

        $this->applyWheres($zero, $query, $model);
    }

    private function applyWheres(ZeroQueryBuilder $zero, BaseBuilder $query, Model $model): void
    {
        $modelClass = $model::class;
        foreach ($query->wheres as $where) {
            if (! is_array($where)) {
                throw new RuntimeException("Global scopes on [{$modelClass}] contain a malformed condition.");
            }
            if (($where['boolean'] ?? 'and') !== 'and') {
                throw new RuntimeException("Global scopes on [{$modelClass}] contain an unsupported OR condition.");
            }

            $type = $where['type'] ?? null;
            if (! is_string($type)) {
                throw new RuntimeException("Global scopes on [{$modelClass}] contain a condition without a type.");
            }
            if ($type === 'Nested' && ($where['query'] ?? null) instanceof BaseBuilder) {
                $this->applyWheres($zero, $where['query'], $model);

                continue;
            }

            $column = $where['column'] ?? null;
            if (! is_string($column)) {
                throw new RuntimeException("Global scopes on [{$modelClass}] contain an unsupported [{$type}] condition.");
            }
            $column = str_contains($column, '.') ? substr($column, strrpos($column, '.') + 1) : $column;

            match ($type) {
                'Basic' => $zero->where($column, $this->operator($where['operator'] ?? '='), $this->literal($where['value'] ?? null, $model)),
                'Null' => $zero->whereNull($column),
                'NotNull' => $zero->whereNotNull($column),
                'In', 'InRaw' => $zero->whereIn($column, $this->literalList($where['values'] ?? null, $model)),
                'NotIn', 'NotInRaw' => $zero->whereNotIn($column, $this->literalList($where['values'] ?? null, $model)),
                default => throw new RuntimeException("Global scopes on [{$modelClass}] contain an unsupported [{$type}] condition."),
            };
        }
    }

    private function operator(mixed $operator): string
    {
        if (! is_string($operator)) {
            throw new RuntimeException('Global scope operators must be strings.');
        }

        return $operator;
    }

    private function literal(mixed $value, Model $model): string|int|float|bool|null
    {
        $value = $value instanceof BackedEnum ? $value->value : $value;

        if (is_scalar($value) || $value === null) {
            return $value;
        }
        if ($value instanceof Stringable) {
            return (string) $value;
        }

        $modelClass = $model::class;

        throw new RuntimeException("Global scopes on [{$modelClass}] contain a non-JSON literal.");
    }

    /** @return list<string|int|float|bool|null> */
    private function literalList(mixed $values, Model $model): array
    {
        if (! is_array($values) || ! array_is_list($values)) {
            $modelClass = $model::class;

            throw new RuntimeException("Global scopes on [{$modelClass}] contain an invalid IN condition.");
        }

        return array_map(fn (mixed $value): string|int|float|bool|null => $this->literal($value, $model), $values);
    }
}
