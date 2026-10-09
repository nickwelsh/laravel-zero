<?php

declare(strict_types=1);

namespace LaravelZero\Http;

use Illuminate\Http\Request;
use LaravelZero\ZeroContext;

class ContextResolver
{
    public function __invoke(Request $request): ZeroContext
    {
        $id = $request->user()?->getAuthIdentifier();

        return new ZeroContext(['user_id' => $id]);
    }
}
