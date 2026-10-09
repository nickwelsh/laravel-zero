<?php

namespace LaravelZero\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
final readonly class ZeroQuery
{
    public function __construct(public array $queries = ['all', 'one']) {}
}
