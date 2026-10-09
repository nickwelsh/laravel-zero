<?php

declare(strict_types=1);

namespace LaravelZero\Attributes;

use Attribute;
use LaravelZero\ContextField;

#[Attribute(Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
final readonly class ZeroServerFill
{
    public function __construct(public string $field, public ContextField $source) {}
}
