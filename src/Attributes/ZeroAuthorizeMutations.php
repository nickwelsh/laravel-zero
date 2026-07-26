<?php

namespace NickWelsh\LaravelZero\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
final readonly class ZeroAuthorizeMutations
{
    /** @param class-string $policy */
    public function __construct(public string $policy) {}
}
