<?php

namespace NickWelsh\LaravelZero\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
final readonly class ZeroQueryCollection
{
    public function __construct(public string $name) {}
}
