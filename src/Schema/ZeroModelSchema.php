<?php

namespace NickWelsh\LaravelZero\Schema;

use InvalidArgumentException;

final readonly class ZeroModelSchema
{
    /**
     * @param  class-string  $modelClass
     * @param  array<string, string>  $columns  server => client
     * @param  list<string>  $primaryKey
     * @param  array<string, ZeroRelationshipSchema>  $relationships
     */
    public function __construct(
        public string $modelClass,
        public string $serverTable,
        public string $clientTable,
        public array $columns,
        public array $primaryKey,
        public array $relationships = [],
    ) {}

    public function clientColumn(string $serverColumn): string
    {
        return $this->columns[$serverColumn] ?? throw new InvalidArgumentException(
            "Unknown Zero column [{$this->serverTable}.{$serverColumn}].",
        );
    }

    public function relationship(string $name): ZeroRelationshipSchema
    {
        return $this->relationships[$name] ?? throw new InvalidArgumentException(
            "Unknown Zero relationship [{$this->modelClass}::{$name}].",
        );
    }
}
