<?php

namespace NickWelsh\LaravelZero\Filters;

use InvalidArgumentException;

final readonly class ZeroFilterSchema
{
    /**
     * @param  class-string  $model
     * @param  array<string, ZeroFilterField>  $fields
     * @param  array<string, ZeroFilterRelationship>  $relationships
     */
    public function __construct(
        public string $model,
        public array $fields,
        public array $relationships,
        public int $maxDepth,
        public int $maxRelationshipDepth,
        public int $maxNodes,
        public int $maxChildren,
        public int $maxInValues,
        public int $maxStringLength,
    ) {}

    public function field(string $id): ZeroFilterField
    {
        return $this->fields[$id] ?? throw new InvalidArgumentException("Unknown or private filter field [{$id}].");
    }

    public function relationship(string $id): ZeroFilterRelationship
    {
        return $this->relationships[$id] ?? throw new InvalidArgumentException("Unknown or private filter relationship [{$id}].");
    }
}
