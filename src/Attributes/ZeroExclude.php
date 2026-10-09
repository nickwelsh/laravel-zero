<?php

declare(strict_types=1);

namespace LaravelZero\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
final readonly class ZeroExclude
{
    /**
     * @param  list<string>  $columns
     */
    public function __construct(public array $columns) {}
}
