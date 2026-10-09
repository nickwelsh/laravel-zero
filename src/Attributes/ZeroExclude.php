<?php

namespace LaravelZero\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
final readonly class ZeroExclude
{
    public function __construct(public array $columns) {}
}
