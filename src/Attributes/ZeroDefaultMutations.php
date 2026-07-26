<?php

namespace NickWelsh\LaravelZero\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
final readonly class ZeroDefaultMutations
{
    /** @param list<class-string> $mutations */
    public function __construct(public array $mutations) {}
}
