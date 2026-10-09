<?php

declare(strict_types=1);

namespace LaravelZero\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
final readonly class ZeroAuthorizeRelation
{
    public function __construct(public string $relation) {}
}
