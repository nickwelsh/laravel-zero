<?php

declare(strict_types=1);

namespace LaravelZero\Attributes;

use Attribute;

/**
 * Opt database columns back in despite $visible, $hidden, or ZeroExclude.
 *
 * An explicit ZeroColumns allowlist remains the upper bound on replication.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class ZeroIncludeColumns
{
    /**
     * @param  list<string>  $columns
     */
    public function __construct(public array $columns) {}
}
