<?php

namespace LaravelZero\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
final readonly class ZeroColumns
{
    public function __construct(public array $columns) {}
}
