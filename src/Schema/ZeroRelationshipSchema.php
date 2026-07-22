<?php

namespace NickWelsh\LaravelZero\Schema;

final readonly class ZeroRelationshipSchema
{
    /** @param non-empty-list<string> $parentColumns @param non-empty-list<string> $childColumns */
    public function __construct(
        public string $name,
        public string $relatedModel,
        public array $parentColumns,
        public array $childColumns,
    ) {}
}
