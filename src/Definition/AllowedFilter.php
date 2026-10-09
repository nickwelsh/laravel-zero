<?php

namespace LaravelZero\Definition;

final readonly class AllowedFilter
{
    private function __construct(public string $name, public string $kind, public string $field) {}

    public static function exact(string $name, ?string $field = null): self
    {
        return new self($name, 'exact', $field ?? $name);
    }

    public static function partial(string $name, ?string $field = null): self
    {
        return new self($name, 'partial', $field ?? $name);
    }

    public static function belongsTo(string $relation): self
    {
        return new self($relation, 'belongsTo', $relation);
    }

    public static function trashed(): self
    {
        return new self('trashed', 'trashed', '');
    }
}
