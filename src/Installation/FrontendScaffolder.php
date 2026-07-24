<?php

namespace NickWelsh\LaravelZero\Installation;

use Illuminate\Contracts\Container\Container;
use NickWelsh\LaravelZero\Frontend\Frontend;
use NickWelsh\LaravelZero\Frontend\React;
use UnexpectedValueException;

final readonly class FrontendScaffolder
{
    public function __construct(private Container $container) {}

    /** @return list<string> */
    public function scaffold(): array
    {
        $class = config('laravel-zero.frontend.framework', React::class);
        if ($class === null) {
            return [];
        }
        if (! is_string($class) || ! is_subclass_of($class, Frontend::class)) {
            throw new UnexpectedValueException('Configuration [laravel-zero.frontend.framework] must be null or a '.Frontend::class.' class.');
        }

        $frontend = $this->container->make($class);
        if (! $frontend instanceof Frontend) {
            throw new UnexpectedValueException("Configured frontend [{$class}] could not be resolved.");
        }

        return $frontend->scaffold();
    }
}
