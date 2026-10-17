<?php

declare(strict_types=1);

namespace ZeroForLaravel\Http;

use Illuminate\Http\Request;
use ZeroForLaravel\ZeroContext;

class ContextResolver
{
    public function __invoke(Request $request): ZeroContext
    {
        $id = $request->user()?->getAuthIdentifier();

        return new ZeroContext(['user_id' => $id]);
    }
}
