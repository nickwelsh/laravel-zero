<?php

namespace NickWelsh\LaravelZero\Context;

use Illuminate\Http\Request;

interface ZeroContextResolver
{
    public function resolve(Request $request): object;
}
