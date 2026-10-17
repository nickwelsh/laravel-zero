<?php

declare(strict_types=1);

namespace LaravelZero\Metadata;

use LaravelZero\Definition\ZeroDefinition;
use stdClass;

/**
 * @phpstan-type Column array{type: 'string'|'number'|'boolean'|'json', nullable: bool, date: bool, enum: list<string|int>|null, hasDefault: bool, default: mixed, cast?: 'boolean'|'integer'|'float'|'string'|'array'|'object'}
 */
final class ModelMetadata
{
    /**
     * @var array<string, array{model: string, cardinality: string, kind?: 'belongsTo'|'hasOne'|'hasMany', path: list<array{source: list<string>, dest: list<string>, table: string}>}>
     */
    public array $relations = [];

    /**
     * @param  class-string<\Illuminate\Database\Eloquent\Model>  $class
     * @param  array<string, Column>  $columns
     * @param  list<string>  $queries
     * @param  list<string>  $mutations
     * @param  list<array{field: string, op: string, value: array{context: string}|array{literal: mixed}}>  $authorization
     * @param  array<string, string>  $serverFill
     * @param  list<string>  $writable
     * @param  array<string, array<string, list<string>>>  $validation
     * @param  list<string>  $authorizationRelations
     * @param  list<string>  $diagnostics
     */
    public function __construct(
        public readonly string $class,
        public readonly string $name,
        public readonly string $table,
        public readonly string $key,
        public readonly array $columns,
        public readonly array $queries,
        public readonly array $mutations,
        public readonly array $authorization,
        public readonly array $serverFill,
        public readonly array $writable,
        public readonly ?string $idGenerator,
        public readonly ?string $createdAt,
        public readonly ?string $updatedAt,
        public readonly ?string $deletedAt,
        public readonly ZeroDefinition $definition,
        public array $validation = [],
        public array $diagnostics = [],
        public readonly array $authorizationRelations = [],
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name, 'table' => $this->table, 'key' => $this->key,
            'columns' => $this->columns, 'queries' => $this->queries,
            'mutations' => $this->mutations, 'authorization' => $this->authorization,
            ...($this->authorizationRelations === [] ? [] : ['authorizationRelations' => $this->authorizationRelations]),
            'validation' => (object) array_map(fn (array $rules): stdClass => (object) $rules, $this->validation),
            'serverFill' => (object) $this->serverFill, 'writable' => $this->writable,
            'idGenerator' => $this->idGenerator,
            'timestamps' => ['created' => $this->createdAt, 'updated' => $this->updatedAt, 'deleted' => $this->deletedAt],
            'filters' => (object) $this->definition->filters,
            'includes' => $this->definition->includes, 'sorts' => $this->definition->sorts,
            'defaultOrder' => $this->definition->defaultOrder,
            'defaultLimit' => $this->definition->defaultLimit, 'maxLimit' => $this->definition->maxLimit,
            'relations' => (object) $this->relations,
            ...($this->definition->nestedMutations === [] ? [] : ['nestedMutations' => (object) array_map(
                fn (array $relations): stdClass => (object) array_map(array_keys(...), $relations),
                $this->definition->nestedMutations,
            )]),
        ];
    }
}
