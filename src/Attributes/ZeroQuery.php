<?php

declare(strict_types=1);

namespace ZeroForLaravel\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
final readonly class ZeroQuery
{
    /**
     * @param  list<string>  $queries
     */
    public function __construct(public array $queries = ['all', 'one']) {}
}
