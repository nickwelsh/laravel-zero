<?php

namespace LaravelZero\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
final readonly class ZeroMutate
{
    public array $operations;

    public function __construct(string ...$operations)
    {
        $this->operations = $operations;
    }
}
