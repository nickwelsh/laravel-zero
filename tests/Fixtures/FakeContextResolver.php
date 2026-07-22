<?php

namespace NickWelsh\LaravelZero\Tests\Fixtures;

use Illuminate\Http\Request;
use NickWelsh\LaravelZero\Context\ZeroContextResolver;

final class FakeContextResolver implements ZeroContextResolver
{
    public function resolve(Request $request): object
    {
        return new TestZeroContext('user-1', 'tenant-1');
    }
}
