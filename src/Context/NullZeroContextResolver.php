<?php

namespace NickWelsh\LaravelZero\Context;

use Illuminate\Http\Request;

final class NullZeroContextResolver implements ZeroContextResolver
{
    public function resolve(Request $request): object
    {
        $class = config('laravel-zero.context.class', ZeroContext::class);

        return new $class;
    }
}
