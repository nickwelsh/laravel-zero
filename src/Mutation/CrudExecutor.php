<?php

declare(strict_types=1);

namespace ZeroForLaravel\Mutation;

use DateTimeInterface;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use LogicException;
use ZeroForLaravel\Definition\NestedMutation;
use ZeroForLaravel\Metadata\ModelMetadata;
use ZeroForLaravel\Metadata\ModelRegistry;
use ZeroForLaravel\Support\Input;
use ZeroForLaravel\ZeroContext;

final readonly class CrudExecutor
{
    public function __construct(private ModelRegistry $registry) {}

    public function execute(string $name, mixed $arguments, ZeroContext $context, ?Authenticatable $user): void
    {
        $parts = explode('.', $name);
        if (count($parts) !== 2) {
            Input::fail('name', 'Unknown mutation.');
        }

        try {
            $metadata = $this->registry->get($parts[0]);
        } catch (LogicException) {
            Input::fail('name', 'Unknown mutation.');
        }

        $operation = $parts[1];
        if (! in_array($operation, $metadata->mutations, true)) {
            Input::fail('name', 'Mutation is not enabled.');
        }

        $this->registry->connection()->transaction(function () use ($metadata, $operation, $arguments, $context, $user): void {
            $seen = [];
            $this->executeNode($metadata, $operation, $arguments, $context, $user, new MutationContext($operation), null, [], 0, $seen);
        });
    }

    /**
     * @param  array<string, mixed>  $bindings
     * @param  array<string, true>  $seen
     */
    private function executeNode(ModelMetadata $metadata, string $operation, mixed $arguments, ZeroContext $context, ?Authenticatable $user, MutationContext $mutationContext, ?NestedMutation $permission, array $bindings, int $depth, array &$seen): Model
    {
        if ($depth > 8 || count($seen) >= 100) {
            Input::fail('relations', 'Nested mutations exceed the maximum depth or record count.');
        }

        $args = Input::object($arguments, 'args');
        Input::keys($args, ['id', 'data', 'now', 'relations'], 'args');
        Input::value($args['id'] ?? null, $metadata->columns[$metadata->key], 'id');
        if ($operation === 'create' && ! match ($metadata->idGenerator) {
            'uuid' => Str::isUuid($args['id']),
            'ulid' => Str::isUlid($args['id']),
            default => false,
        }) {
            Input::fail('id', 'Invalid generated primary key.');
        }

        if (! is_string($args['id']) && ! is_int($args['id'])) {
            Input::fail('id', 'Expected a record id.');
        }

        $identity = $metadata->table.':'.$args['id'];
        if (isset($seen[$identity])) {
            Input::fail('id', 'A record may only occur once in a nested mutation.');
        }

        $seen[$identity] = true;
        if (! is_int($args['now'] ?? null) || abs($args['now']) > 8640000000000000) {
            Input::fail('now', 'Expected stable epoch milliseconds.');
        }

        $relations = Input::object(array_key_exists('relations', $args) ? $args['relations'] : [], 'relations');
        $allowed = $metadata->definition->nestedMutations[$operation] ?? [];
        Input::keys($relations, array_keys($allowed), 'relations');
        $children = [];
        $creatingBelongsTo = [];
        foreach ($relations as $name => $input) {
            $spec = Input::object($input, $name);
            Input::keys($spec, ['operation', 'records'], $name);
            $childOperation = $spec['operation'] ?? null;
            if (! is_string($childOperation) || ! isset($allowed[$name][$childOperation])) {
                Input::fail($name, 'Nested operation is not allowed.');
            }

            $records = $spec['records'] ?? null;
            $relation = $metadata->relations[$name];
            if (! is_array($records) || ! array_is_list($records) || count($records) > 100 || ($relation['cardinality'] === 'one' && count($records) !== 1)) {
                Input::fail($name, 'Invalid nested record count.');
            }

            $children[$name] = ['permission' => $allowed[$name][$childOperation], 'records' => $records];
            if (($relation['kind'] ?? null) === 'belongsTo') {
                $childArgs = Input::object($records[0], $name);
                $foreignKey = $relation['path'][0]['source'][0];
                if (array_key_exists($foreignKey, $bindings)) {
                    Input::fail($name, 'Conflicting relationship bindings.');
                }

                $bindings[$foreignKey] = $childArgs['id'] ?? null;
                if ($childOperation === 'create') {
                    $creatingBelongsTo[] = $foreignKey;
                }
            }
        }

        $data = Input::object($args['data'] ?? [], 'data');
        Input::keys($data, array_values(array_diff($metadata->writable, array_keys($bindings))), 'data');
        $injected = $operation === 'create' ? $bindings : array_intersect_key($bindings, array_flip($creatingBelongsTo));
        $data = [...$data, ...$injected];
        $this->validateData($metadata, $operation, $data, array_keys($injected));
        $model = new $metadata->class;
        if ($operation !== 'create') {
            $query = $model->newQuery();
            if (in_array($operation, ['restore', 'force_delete'], true)) {
                $query->withoutGlobalScope(SoftDeletingScope::class);
            }

            $model = $query->whereKey($args['id'])->lockForUpdate()->firstOrFail();
            foreach ($bindings as $field => $value) {
                if ($model->getAttribute($field) !== $value && (! in_array($field, $creatingBelongsTo, true) || $model->getAttribute($field) !== null)) {
                    Input::fail($field, 'The record does not belong to this relationship.');
                }
            }
        } else {
            $model->forceFill([$metadata->key => $args['id']]);
            foreach ($metadata->serverFill as $field => $source) {
                $model->setAttribute($field, Input::value($context->get($source), $metadata->columns[$field], $field));
            }
        }

        $ability = match ($operation) {
            'destroy' => 'delete', 'force_delete' => 'forceDelete', default => $operation,
        };
        if (! $permission instanceof NestedMutation || ! $permission->usingParent) {
            Gate::forUser($user)->authorize($ability, $operation === 'create' ? $metadata->class : $model);
        }

        if ($permission?->ability !== null) {
            Gate::forUser($user)->authorize($permission->ability, $operation === 'create' ? [$mutationContext->parent] : [$mutationContext->parent, $model]);
        }

        // BelongsTo dependencies must exist before the parent request validates its foreign key.
        // The root policy has already authorized this operation; all work remains transactional.
        foreach ($children as $name => $child) {
            if (($metadata->relations[$name]['kind'] ?? null) === 'belongsTo') {
                $this->executeChildren($metadata, $model, $name, $child, $context, $user, $depth, $seen);
            }
        }

        if (isset($metadata->definition->requests[$operation])) {
            $data = $this->formRequest($metadata, $metadata->definition->requests[$operation], $data, $args['id'], $model, $user, $mutationContext);
            Input::keys($data, [...array_values(array_diff($metadata->writable, array_keys($bindings))), ...array_keys($injected)], 'data');
            foreach ($injected as $field => $value) {
                if (! array_key_exists($field, $data) || $data[$field] !== $value) {
                    Input::fail($field, 'Relationship keys cannot be normalized.');
                }
            }

            $this->validateData($metadata, $operation, $data, array_keys($injected));
        }

        foreach ($data as $field => $value) {
            $data[$field] = Input::eloquentValue($value, $metadata->columns[$field], $field);
            if ($metadata->columns[$field]['date'] && $value !== null) {
                $data[$field] = \Illuminate\Support\Facades\Date::createFromTimestampMs(Input::timestamp($value, $field), 'UTC');
            }
        }

        if ($operation === 'create') {
            foreach ($metadata->serverFill as $field => $source) {
                $data[$field] = Input::value($context->get($source), $metadata->columns[$field], $field);
            }
        }

        $saved = match ($operation) {
            'create', 'update' => $model->forceFill($data)->saveOrFail(),
            'destroy' => $model->deleteOrFail(),
            'restore' => method_exists($model, 'restore') ? $model->restore() : Input::fail('name', 'Restore requires SoftDeletes.'),
            'force_delete' => $model->forceDelete(),
            default => Input::fail('name', 'Unknown mutation.'),
        };
        if ($saved === false) {
            Input::fail('model', 'An Eloquent event cancelled this mutation.');
        }

        if ($model->getKey() !== $args['id']) {
            Input::fail('id', 'An Eloquent event changed the mutation identity.');
        }

        foreach ($bindings as $field => $value) {
            if ($model->getAttribute($field) !== $value) {
                Input::fail($field, 'An Eloquent event changed a relationship key.');
            }
        }

        foreach ($children as $name => $child) {
            if (($metadata->relations[$name]['kind'] ?? null) !== 'belongsTo') {
                $this->executeChildren($metadata, $model, $name, $child, $context, $user, $depth, $seen);
            }
        }

        return $model;
    }

    /**
     * @param  array{permission: NestedMutation, records: list<mixed>}  $child
     * @param  array<string, true>  $seen
     */
    private function executeChildren(ModelMetadata $metadata, Model $parent, string $name, array $child, ZeroContext $context, ?Authenticatable $user, int $depth, array &$seen): void
    {
        $relation = $metadata->relations[$name];
        $target = $this->registry->get($relation['model']);
        $permission = $child['permission'];
        $belongsTo = ($relation['kind'] ?? null) === 'belongsTo';
        if (($relation['kind'] ?? null) === 'hasOne' && $permission->operation === 'create') {
            $eloquentRelation = $parent->$name();
            if (! $eloquentRelation instanceof Relation) {
                throw new LogicException('Nested mutations require an Eloquent relation.');
            }

            if ($eloquentRelation->exists()) {
                Input::fail($name, 'The relationship already has a record.');
            }
        }

        foreach ($child['records'] as $index => $record) {
            $path = $relation['cardinality'] === 'many' ? $name.'.'.$index : $name;
            try {
                if ($belongsTo && $permission->operation === 'update') {
                    $args = Input::object($record, 'args');
                    if (! $parent->exists || $parent->getAttribute($relation['path'][0]['source'][0]) !== ($args['id'] ?? null)) {
                        Input::fail('id', 'The record does not belong to this relationship.');
                    }
                }

                $bindings = $belongsTo ? [] : [$relation['path'][0]['dest'][0] => $parent->getKey()];
                $this->executeNode($target, $permission->operation, $record, $context, $user, new MutationContext($permission->operation, $parent, $name), $permission, $bindings, $depth + 1, $seen);
            } catch (ValidationException $error) {
                $messages = [];
                foreach ($error->errors() as $field => $errors) {
                    $field = preg_replace('/^(?:args\\.)?data\\./', '', $field) ?? $field;
                    $messages[$path.'.'.$field] = $errors;
                }

                throw ValidationException::withMessages($messages);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<string>  $bindings
     */
    private function validateData(ModelMetadata $model, string $operation, array $data, array $bindings = []): void
    {
        Input::keys($data, in_array($operation, ['create', 'update'], true) ? array_values(array_unique([...$model->writable, ...$bindings])) : [], 'data');
        foreach ($data as $field => $value) {
            Input::value($value, $model->columns[$field], $field);
        }

        if ($operation === 'create') {
            foreach ($model->writable as $field) {
                $column = $model->columns[$field];
                if (! array_key_exists($field, $data) && ! $column['nullable'] && ! $column['hasDefault']) {
                    Input::fail($field, 'This field is required.');
                }
            }
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function formRequest(ModelMetadata $metadata, string $class, array $data, mixed $id, Model $model, ?Authenticatable $user, MutationContext $mutationContext): array
    {
        if (! is_subclass_of($class, FormRequest::class)) {
            throw new LogicException("{$class} must be a FormRequest.");
        }

        $request = FormRequest::createFrom(resolve('request'), new $class);
        $request->setContainer(app())->setRedirector(resolve('redirect'));
        $request->attributes->set(MutationContext::class, $mutationContext);
        foreach ($data as $field => $value) {
            $data[$field] = Input::eloquentValue($value, $metadata->columns[$field], $field);
            if ($metadata->columns[$field]['date'] && $value !== null) {
                $data[$field] = \Illuminate\Support\Facades\Date::createFromTimestampMs(Input::timestamp($value, $field), 'UTC')->toISOString();
            }
        }

        // The transport query and uploaded files are not mutation input.
        $request->query->replace([]);
        $request->files->replace([]);
        $request->replace($data + ['id' => $id]);
        $request->setUserResolver(fn (): ?\Illuminate\Contracts\Auth\Authenticatable => $user);

        $outerRoute = resolve('request')->route();
        if ($outerRoute !== null) {
            $route = clone $outerRoute;
            $route->setParameter('model', $model);
            $request->setRouteResolver(fn (): object => $route);
        }

        $request->validateResolved();
        $data = Input::object($request->all(), 'data');
        if (($data['id'] ?? $id) !== $id) {
            Input::fail('id', 'The mutation identity cannot be normalized.');
        }

        unset($data['id']);
        foreach ($data as $field => $value) {
            if (isset($metadata->columns[$field])) {
                $data[$field] = Input::wireValue($value, $metadata->columns[$field], $field);
            }

            if (($metadata->columns[$field]['date'] ?? false) && $value !== null) {
                if (! is_string($value) && ! is_int($value) && ! is_float($value) && ! $value instanceof DateTimeInterface) {
                    Input::fail($field, 'Invalid normalized date.');
                }

                try {
                    $data[$field] = \Illuminate\Support\Facades\Date::parse($value, 'UTC')->getTimestampMs();
                } catch (InvalidArgumentException) {
                    Input::fail($field, 'Invalid normalized date.');
                }
            }
        }

        return $data;
    }
}
