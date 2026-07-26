<?php

namespace NickWelsh\LaravelZero\Dynamic;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\Relation;
use NickWelsh\LaravelZero\Contracts\ZeroSchemaRegistry;
use ReflectionMethod;
use ReflectionNamedType;
use RuntimeException;

final readonly class DynamicModelMutation
{
    /**
     * @param  class-string<Model>  $modelClass
     * @param  class-string  $policyClass
     * @param  list<string>  $operations
     */
    public function __construct(
        private string $modelClass,
        public string $name,
        private string $policyClass,
        private array $operations,
        private ZeroSchemaRegistry $schemas,
    ) {}

    /** @return class-string<Model> */
    public function modelClass(): string
    {
        return $this->modelClass;
    }

    /** @return list<string> */
    public function operations(): array
    {
        return $this->operations;
    }

    public function allows(string $operation): bool
    {
        return in_array($operation, $this->operations, true);
    }

    /** @return array<string, Relation<Model, Model, mixed>> */
    public function relationships(): array
    {
        $model = new $this->modelClass;
        $relationships = [];
        $reflection = new \ReflectionClass($this->modelClass);
        foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->getDeclaringClass()->getName() !== $this->modelClass || $method->getNumberOfRequiredParameters() !== 0) {
                continue;
            }
            $type = $method->getReturnType();
            if (! $type instanceof ReflectionNamedType || ! is_a($type->getName(), Relation::class, true)) {
                continue;
            }
            $relation = $model->{$method->getName()}();
            if ($relation instanceof Relation) {
                $relationships[$method->getName()] = $relation;
            }
        }

        return $relationships;
    }

    /**
     * @param  array<string, mixed>  $args
     */
    public function execute(string $operation, array $args, ?object $user): void
    {
        match ($operation) {
            'create' => $this->create($args, $user),
            'update' => $this->update($args, $user),
            'delete' => $this->delete($args, $user),
            'relation' => $this->relation($args, $user),
            default => throw new RuntimeException("Unknown default Zero mutation operation [{$operation}]."),
        };
    }

    /**
     * @param  array<string, mixed>  $args
     */
    private function create(array $args, ?object $user): void
    {
        $this->ensureAllowed('create');
        $values = $this->object($args['values'] ?? null, 'Default Zero create values must be an object.');
        $relations = $this->object($args['relations'] ?? [], 'Default Zero create relations must be an object.');
        $model = new $this->modelClass;
        $this->authorize('create', $user);
        $model->forceFill($this->serverValues($model, $values))->saveOrFail();

        foreach ($relations as $name => $value) {
            $this->createRelation($model, $name, $value);
        }
    }

    /**
     * @param  array<string, mixed>  $args
     */
    private function update(array $args, ?object $user): void
    {
        $this->ensureAllowed('update');
        $model = $this->find($this->object($args['key'] ?? null, 'Default Zero update key must be an object.'));
        if ($model === null) {
            return;
        }
        $this->authorize('update', $user, $model);
        $values = $this->serverValues($model, $this->object($args['values'] ?? null, 'Default Zero update values must be an object.'));
        $model->forceFill(array_diff_key($values, array_flip($this->schemas->model($this->modelClass)->primaryKey)))->saveOrFail();
    }

    /**
     * @param  array<string, mixed>  $args
     */
    private function delete(array $args, ?object $user): void
    {
        $this->ensureAllowed('delete');
        $model = $this->find($this->object($args['key'] ?? null, 'Default Zero delete key must be an object.'));
        if ($model === null) {
            return;
        }
        $this->authorize('delete', $user, $model);
        $model->deleteOrFail();
    }

    /**
     * @param  array<string, mixed>  $args
     */
    private function relation(array $args, ?object $user): void
    {
        $this->ensureAllowed('update');
        $model = $this->find($this->object($args['key'] ?? null, 'Default Zero relation key must be an object.'));
        if ($model === null) {
            return;
        }
        $this->authorize('update', $user, $model);
        $name = $args['relation'] ?? null;
        $operation = $args['operation'] ?? null;
        if (! is_string($name) || ! is_string($operation)) {
            throw new RuntimeException('Default Zero relation mutation requires string relation and operation names.');
        }
        $relation = $this->eloquentRelation($model, $name);
        if (! $relation instanceof BelongsToMany) {
            throw new RuntimeException("Default Zero relation [{$this->modelClass}::{$name}] must be a belongs-to-many or morph-to-many relation.");
        }
        $ids = $args['ids'] ?? [];
        $pivot = $this->object($args['pivot'] ?? [], 'Default Zero pivot values must be an object.');

        match ($operation) {
            'attach' => $relation->attach($ids, $pivot),
            'detach' => $relation->detach($ids),
            'sync' => $relation->sync($this->syncable($ids)),
            'syncWithoutDetaching' => $relation->syncWithoutDetaching($this->syncable($ids)),
            'syncWithPivotValues' => $relation->syncWithPivotValues($this->list($ids), $pivot),
            'toggle' => $relation->toggle($ids),
            'updateExistingPivot' => $relation->updateExistingPivot($args['relatedId'] ?? null, $pivot),
            default => throw new RuntimeException("Unknown default Zero relation operation [{$operation}]."),
        };
    }

    private function createRelation(Model $model, string $name, mixed $value): void
    {
        $relation = $this->eloquentRelation($model, $name);
        if ($relation instanceof HasOne) {
            $related = $relation->getRelated()->newInstance();
            $related->forceFill($this->serverValues($related, $this->object($value, "Nested Zero relation [{$name}] must be an object.")));
            $relation->save($related);

            return;
        }
        if ($relation instanceof HasMany) {
            foreach ($this->list($value) as $item) {
                $related = $relation->getRelated()->newInstance();
                $related->forceFill($this->serverValues($related, $this->object($item, "Nested Zero relation [{$name}] items must be objects.")));
                $relation->save($related);
            }

            return;
        }
        if ($relation instanceof BelongsToMany) {
            $ids = [];
            foreach ($this->list($value) as $item) {
                if (is_scalar($item)) {
                    $ids[] = $item;

                    continue;
                }
                $related = $relation->getRelated();
                $values = $this->serverValues($related, $this->object($item, "Nested Zero relation [{$name}] items must be IDs or objects."));
                $key = $related->getKeyName();
                $instance = array_key_exists($key, $values) ? $related->newQuery()->where($key, $values[$key])->first() : null;
                $instance ??= $related->newInstance();
                $instance->forceFill($values)->saveOrFail();
                $ids[] = $instance->getKey();
            }
            $relation->syncWithoutDetaching($ids);

            return;
        }

        throw new RuntimeException("Nested default Zero create does not support relation [{$this->modelClass}::{$name}].");
    }

    /** @return Relation<Model, Model, mixed> */
    private function eloquentRelation(Model $model, string $name): Relation
    {
        if (! method_exists($model, $name)) {
            throw new RuntimeException("Unknown default Zero relation [{$this->modelClass}::{$name}].");
        }
        $relation = $model->{$name}();
        if (! $relation instanceof Relation) {
            throw new RuntimeException("[{$this->modelClass}::{$name}()] is not an Eloquent relation.");
        }

        return $relation;
    }

    /** @param array<string, mixed> $key */
    private function find(array $key): ?Model
    {
        $model = new $this->modelClass;
        $values = $this->serverValues($model, $key);
        $query = $model->newQuery();
        foreach ($this->schemas->model($this->modelClass)->primaryKey as $column) {
            if (! array_key_exists($column, $values)) {
                throw new RuntimeException("Default Zero mutation requires primary-key field [{$column}].");
            }
            $query->where($column, $values[$column]);
        }

        return $query->first();
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private function serverValues(Model $model, array $values): array
    {
        $schema = $this->schemas->model($model::class);
        $columns = array_flip($schema->columns);
        $timestamps = $model->usesTimestamps() ? [$model->getCreatedAtColumn(), $model->getUpdatedAtColumn()] : [];
        $result = [];
        foreach ($values as $client => $value) {
            if ($client === '__zero') {
                continue;
            }
            if (! isset($columns[$client])) {
                throw new RuntimeException("Unknown Zero mutation field [{$schema->clientTable}.{$client}].");
            }
            $server = $columns[$client];
            if (! in_array($server, $timestamps, true)) {
                $result[$server] = $value;
            }
        }

        return $result;
    }

    private function authorize(string $ability, ?object $user, ?Model $model = null): void
    {
        if ($user === null) {
            throw new AuthorizationException('Unauthenticated.');
        }
        $policy = app($this->policyClass);
        if (! is_object($policy) || ! method_exists($policy, $ability)) {
            throw new RuntimeException("Default Zero policy [{$this->policyClass}] must define [{$ability}()].");
        }
        $method = new ReflectionMethod($policy, $ability);
        $allowed = $method->invokeArgs($policy, $model === null ? [$user] : [$user, $model]);
        if ($allowed !== true) {
            throw new AuthorizationException;
        }
    }

    private function ensureAllowed(string $operation): void
    {
        if (! $this->allows($operation)) {
            throw new AuthorizationException("Default Zero mutation [{$this->name}.{$operation}] is not enabled.");
        }
    }

    /** @return array<string, mixed> */
    private function object(mixed $value, string $message): array
    {
        if (! is_array($value) || array_is_list($value) && $value !== []) {
            throw new RuntimeException($message);
        }

        $object = [];
        foreach ($value as $key => $item) {
            if (! is_string($key)) {
                throw new RuntimeException($message);
            }
            $object[$key] = $item;
        }

        return $object;
    }

    /** @return list<mixed> */
    private function list(mixed $value): array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            throw new RuntimeException('Default Zero relation values must be a list.');
        }

        return $value;
    }

    /** @return array<mixed>|int|string */
    private function syncable(mixed $value): array|int|string
    {
        if (is_int($value) || is_string($value) || is_array($value)) {
            return $value;
        }

        throw new RuntimeException('Default Zero sync values must be an ID, list, or ID-to-pivot map.');
    }
}
