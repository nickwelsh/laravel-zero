<?php

namespace NickWelsh\LaravelZero\Schema;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use NickWelsh\LaravelZero\Contracts\ZeroSchemaRegistry;
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
        $casing = config('eloquent-zero.column_name_casing');
        $mapped = [];

        foreach ($columns as $column) {
            $mapped[$column] = match ($casing?->value ?? $casing) {
                'snake' => Str::snake($column),
                default => Str::camel($column),
            };
        }

        $clientTable = Str::camel($model->getTable());

        return $this->schemas[$modelClass] = new ZeroModelSchema(
            $modelClass,
            $model->getTable(),
            $clientTable,
            $mapped,
            [$model->getKeyName()],
        );
    }

    public function models(): iterable
    {
        return $this->schemas;
    }
}
