<?php

declare(strict_types=1);

namespace LaravelZero\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
final readonly class ZeroMutate
{
    /**
     * @var list<string>
     */
    public array $operations;

    public function __construct(string ...$operations)
    {
        $this->operations = $operations;
    }
}
