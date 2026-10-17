<?php

declare(strict_types=1);

namespace ZeroForLaravel\Attributes;

use Attribute;

/**
 * Exclude database columns in addition to Eloquent's $hidden.
 *
 * Applies within the $visible or ZeroColumns allowlist. ZeroIncludeColumns
 * can explicitly opt an excluded column back in.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class ZeroExclude
{
    /**
     * @param  list<string>  $columns
     */
    public function __construct(public array $columns) {}
}
