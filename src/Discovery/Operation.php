<?php

namespace NickWelsh\LaravelZero\Discovery;

use ReflectionMethod;

final readonly class Operation
{
    /** @param class-string $class */
    public function __construct(
        public string $kind,
        public string $name,
        public string $prefix,
        public string $class,
        public ReflectionMethod $method,
    ) {}

    public function instance(): object
    {
        $instance = app($this->class);

        if (! is_object($instance)) {
            throw new \UnexpectedValueException("Container binding [{$this->class}] must resolve to an object.");
        }

        return $instance;
    }
}
