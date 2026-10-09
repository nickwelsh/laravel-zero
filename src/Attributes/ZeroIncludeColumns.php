<?php

namespace LaravelZero\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
final readonly class ZeroIncludeColumns
{
    public function __construct(public array $columns) {}
}
