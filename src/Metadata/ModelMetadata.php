<?php

namespace LaravelZero\Metadata;

use LaravelZero\Definition\ZeroDefinition;

final class ModelMetadata
{
    public array $relations = [];

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
        public readonly array $validation = [],
        public readonly array $diagnostics = [],
    ) {}

    public function toArray(): array
    {
        return [
            'name' => $this->name, 'table' => $this->table, 'key' => $this->key,
            'columns' => $this->columns, 'queries' => $this->queries,
            'mutations' => $this->mutations, 'authorization' => $this->authorization,
            'validation' => (object) array_map(fn ($rules) => (object) $rules, $this->validation),
            'serverFill' => (object) $this->serverFill, 'writable' => $this->writable,
            'idGenerator' => $this->idGenerator,
            'timestamps' => ['created' => $this->createdAt, 'updated' => $this->updatedAt, 'deleted' => $this->deletedAt],
            'filters' => (object) $this->definition->filters,
            'includes' => $this->definition->includes, 'sorts' => $this->definition->sorts,
            'defaultOrder' => $this->definition->defaultOrder,
            'defaultLimit' => $this->definition->defaultLimit, 'maxLimit' => $this->definition->maxLimit,
            'relations' => (object) $this->relations,
        ];
    }
}
