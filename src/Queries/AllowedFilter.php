<?php

namespace NickWelsh\LaravelZero\Queries;

final readonly class AllowedFilter
{
    /** @param non-empty-list<string> $operators */
    private function __construct(public string $name, public array $operators) {}

    public static function exact(string $name): self
    {
        return new self($name, ['=', '!=', 'IN', 'NOT IN', 'IS', 'IS NOT']);
    }

    public static function field(string $name): self
    {
        return new self($name, ['=', '!=', '<', '>', '<=', '>=', 'LIKE', 'NOT LIKE', 'ILIKE', 'NOT ILIKE', 'IN', 'NOT IN', 'IS', 'IS NOT']);
    }

    public function allows(string $operator): bool
    {
        return in_array(strtoupper($operator), $this->operators, true);
    }
}
