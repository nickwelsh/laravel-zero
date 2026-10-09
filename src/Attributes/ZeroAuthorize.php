<?php

namespace LaravelZero\Attributes;

use Attribute;
use LaravelZero\ContextField;

#[Attribute(Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
final readonly class ZeroAuthorize
{
    public function __construct(public string $field, public string $operator, public ContextField|string|int|float|bool|null $value) {}
}
