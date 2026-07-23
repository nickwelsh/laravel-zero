<?php

namespace NickWelsh\LaravelZero\Mutations;

use Illuminate\Database\Eloquent\Model;
use NickWelsh\LaravelZero\Contracts\ZeroSchemaRegistry;
use RuntimeException;

final class ZeroMutationBuilder
{
    /** @var list<string> */
    private array $serverOnlyFields = [];

    /** @var list<string> */
    private array $ignoredFields = [];

    public function __construct(private readonly ZeroSchemaRegistry $registry, private readonly string $modelClass) {}

    /** @param string|list<string> $fields */
    public function serverOnly(string|array $fields): self
    {
        $this->serverOnlyFields = array_values(array_unique([...$this->serverOnlyFields, ...(array) $fields]));

        return $this;
    }

    /** @param string|list<string> $fields */
    public function ignore(string|array $fields): self
    {
        $this->ignoredFields = array_values(array_unique([...$this->ignoredFields, ...(array) $fields]));

        return $this;
    }

    /** @return list<string> */
    public function serverOnlyFields(): array
    {
        return $this->serverOnlyFields;
    }

    /** @return list<string> */
    public function ignoredFields(): array
    {
        return $this->ignoredFields;
    }

    /** @param array<string, mixed> $values */
    public function create(array $values): Model
    {
        $model = $this->model();
        $model->forceFill($this->withoutIgnoredFields($values))->saveOrFail();

        return $model;
    }

    /** @param array<string, mixed> $values */
    public function update(array $values): ?Model
    {
        $values = $this->withoutIgnoredFields($values);
        $model = $this->find($values);
        if (! $model) {
            return null;
        }
        $keys = $this->registry->model($this->modelClass)->primaryKey;
        $model->forceFill(array_diff_key($values, array_flip($keys)))->saveOrFail();

        return $model;
    }

    /** @param array<string, mixed> $values */
    public function upsert(array $values): Model
    {
        $values = $this->withoutIgnoredFields($values);
        $keys = $this->keyValues($values);
        $query = $this->model()->newQuery();
        foreach ($keys as $column => $value) {
            $query->where($column, $value);
        }
        $model = $query->first() ?? $this->model()->forceFill($keys);
        $model->forceFill($values)->saveOrFail();

        return $model;
    }

    /** @param array<string, mixed> $values */
    public function delete(array $values): bool
    {
        return (bool) $this->find($this->withoutIgnoredFields($values))?->delete();
    }

    /** @param array<string, mixed> $values @return array<string, mixed> */
    private function withoutIgnoredFields(array $values): array
    {
        return array_diff_key($values, array_flip($this->ignoredFields));
    }

    private function model(): Model
    {
        /** @var Model $model */
        $model = new $this->modelClass;
        $configured = config('laravel-zero.database.connection') ?: config('database.default');
        $actual = $model->getConnectionName() ?: config('database.default');
        if ($actual !== $configured) {
            throw new RuntimeException("Zero mutation model [{$this->modelClass}] uses [{$actual}], expected [{$configured}].");
        }

        return $model;
    }

    /** @param array<string, mixed> $values */
    private function find(array $values): ?Model
    {
        $query = $this->model()->newQuery();
        foreach ($this->keyValues($values) as $column => $value) {
            $query->where($column, $value);
        }

        return $query->first();
    }

    /** @param array<string, mixed> $values @return array<string, mixed> */
    private function keyValues(array $values): array
    {
        $keys = $this->registry->model($this->modelClass)->primaryKey;
        $result = array_intersect_key($values, array_flip($keys));
        if (count($result) !== count($keys)) {
            throw new RuntimeException('Zero mutation requires all primary-key fields: '.implode(', ', $keys).'.');
        }

        return $result;
    }
}
