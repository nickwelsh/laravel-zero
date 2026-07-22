<?php

namespace NickWelsh\LaravelZero\Schema;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Str;
use NickWelsh\EloquentZero\Attributes\ZeroColumns;
use NickWelsh\EloquentZero\Attributes\ZeroName;
use NickWelsh\LaravelZero\Contracts\ZeroSchemaRegistry;
use ReflectionClass;
use ReflectionMethod;
use RuntimeException;

/**
 * Runtime bridge until eloquent-zero exposes its planned registry contract.
 * Applications may bind ZeroSchemaRegistry to eloquent-zero's metadata provider.
 */
final class EloquentZeroSchemaRegistry implements ZeroSchemaRegistry
{
    /** @var array<class-string, ZeroModelSchema> */
    private array $schemas = [];

    public function model(string $modelClass): ZeroModelSchema
    {
        if (isset($this->schemas[$modelClass])) {
            return $this->schemas[$modelClass];
        }

        if (! is_subclass_of($modelClass, Model::class)) {
            throw new RuntimeException("[{$modelClass}] is not an Eloquent model.");
        }

        /** @var Model $model */
        $model = new $modelClass;
        $columns = $model->getConnection()->getSchemaBuilder()->getColumnListing($model->getTable());
        $reflection = new ReflectionClass($modelClass);
        $allowed = $reflection->getAttributes(ZeroColumns::class)[0] ?? null;
        if ($allowed !== null) {
            $columns = array_values(array_intersect($columns, $allowed->newInstance()->columns));
        }
        if (! in_array($model->getKeyName(), $columns, true)) {
            $columns[] = $model->getKeyName();
        }
        $casing = config('eloquent-zero.column_name_casing');
        $mapped = [];

        foreach ($columns as $column) {
            $mapped[$column] = match ($casing?->value ?? $casing) {
                'snake' => Str::snake($column),
                default => Str::camel($column),
            };
        }

        $name = $reflection->getAttributes(ZeroName::class)[0] ?? null;
        $clientTable = $name?->newInstance()->name ?? Str::camel($model->getTable());
        $relationships = [];

        foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            $returnType = $method->getReturnType();
            if ($method->getDeclaringClass()->getName() !== $modelClass || $method->getNumberOfParameters() !== 0 || ! $returnType instanceof \ReflectionNamedType || ! is_a($returnType->getName(), Relation::class, true)) {
                continue;
            }
            $relation = $model->{$method->getName()}();
            $definition = match (true) {
                $relation instanceof BelongsTo => [$relation->getForeignKeyName(), $relation->getOwnerKeyName()],
                $relation instanceof HasMany, $relation instanceof HasOne => [$relation->getLocalKeyName(), $relation->getForeignKeyName()],
                default => null,
            };
            if ($definition !== null) {
                $relationships[$method->getName()] = new ZeroRelationshipSchema(
                    $method->getName(),
                    $relation->getRelated()::class,
                    [$definition[0]],
                    [$definition[1]],
                );
            }
        }

        return $this->schemas[$modelClass] = new ZeroModelSchema(
            $modelClass,
            $model->getTable(),
            $clientTable,
            $mapped,
            [$model->getKeyName()],
            $relationships,
        );
    }

    public function models(): iterable
    {
        return $this->schemas;
    }
}
