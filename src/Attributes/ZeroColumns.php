<?php

declare(strict_types=1);

namespace LaravelZero\Attributes;

use Attribute;

/**
 * Set an explicit replication column allowlist instead of Eloquent's $visible.
 *
 * Eloquent's $hidden and ZeroExclude still remove columns. ZeroIncludeColumns
 * can restore excluded columns but cannot expand this allowlist.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class ZeroColumns
{
    /**
     * @param  list<string>  $columns
     */
    public function __construct(public array $columns) {}
}
