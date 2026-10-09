<?php

namespace LaravelZero\Attributes;

use Attribute;
use LaravelZero\ContextField;

#[Attribute(Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
final readonly class ZeroServerFill
{
    public function __construct(public string $field, public ContextField $source) {}
}
