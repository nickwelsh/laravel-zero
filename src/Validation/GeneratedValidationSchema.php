<?php

namespace NickWelsh\LaravelZero\Validation;

final readonly class GeneratedValidationSchema
{
    /** @param array<string, list<string>> $notices */
    public function __construct(
        public string $source,
        public array $notices = [],
    ) {}
}
