<?php

namespace LaravelZero\Http;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class AuthenticateZeroCache
{
    public function handle(Request $request, Closure $next): Response
    {
        $key = config('zero.api_key');
        abort_unless(is_string($key) && $key !== '', 503, 'Zero API key is not configured.');
        abort_unless(hash_equals($key, (string) $request->header('X-Api-Key')), 403);
        abort_if(strlen($request->getContent()) > 1048576, 413);
        $request->headers->set('Accept', 'application/json');

        return $next($request);
    }
}
