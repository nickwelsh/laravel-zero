<?php

namespace NickWelsh\LaravelZero\Discovery;

use ReflectionMethod;

final readonly class Operation
{
    public function __construct(
        public string $kind,
        public string $name,
        public string $prefix,
        public string $class,
        public ReflectionMethod $method,
    ) {}

    public function instance(): object
    {
        return app($this->class);
    }
}
