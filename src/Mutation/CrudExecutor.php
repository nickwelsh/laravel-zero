<?php

declare(strict_types=1);

namespace LaravelZero\Mutation;

use DateTimeInterface;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LaravelZero\Metadata\ModelMetadata;
use LaravelZero\Metadata\ModelRegistry;
use LaravelZero\Support\Input;
use LaravelZero\ZeroContext;
use LogicException;

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

        $args = Input::object($arguments, 'args');
        Input::keys($args, ['id', 'data', 'now'], 'args');
        Input::value($args['id'] ?? null, $metadata->columns[$metadata->key], 'id');
        if ($operation === 'create' && ! match ($metadata->idGenerator) {
            'uuid' => Str::isUuid($args['id']),
            'ulid' => Str::isUlid($args['id']),
            default => false,
        }) {
            Input::fail('id', 'Invalid generated primary key.');
        }

        if (! is_int($args['now'] ?? null) || abs($args['now']) > 8640000000000000) {
            Input::fail('now', 'Expected stable epoch milliseconds.');
        }

        $data = Input::object($args['data'] ?? [], 'data');
        $this->validateData($metadata, $operation, $data);
        $model = new $metadata->class;
        if ($operation !== 'create') {
            $query = $model->newQuery();
            if (in_array($operation, ['restore', 'force_delete'], true)) {
                $query->withoutGlobalScope(SoftDeletingScope::class);
            }

            $model = $query->whereKey($args['id'])->lockForUpdate()->firstOrFail();
        }

        $ability = match ($operation) {
            'destroy' => 'delete', 'force_delete' => 'forceDelete', default => $operation,
        };
        Gate::forUser($user)->authorize($ability, $operation === 'create' ? $metadata->class : $model);
        if (isset($metadata->definition->requests[$operation])) {
            $data = $this->formRequest($metadata, $metadata->definition->requests[$operation], $data, $args['id'], $model, $user);
            $this->validateData($metadata, $operation, $data);
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

            $model->forceFill([$metadata->key => $args['id']]);
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
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function validateData(ModelMetadata $model, string $operation, array $data): void
    {
        Input::keys($data, in_array($operation, ['create', 'update'], true) ? $model->writable : [], 'data');
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
    private function formRequest(ModelMetadata $metadata, string $class, array $data, mixed $id, Model $model, ?Authenticatable $user): array
    {
        if (! is_subclass_of($class, FormRequest::class)) {
            throw new LogicException("{$class} must be a FormRequest.");
        }

        $request = FormRequest::createFrom(resolve('request'), new $class);
        $request->setContainer(app())->setRedirector(resolve('redirect'));
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
