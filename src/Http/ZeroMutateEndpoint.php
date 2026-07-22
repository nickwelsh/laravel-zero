<?php

namespace NickWelsh\LaravelZero\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use NickWelsh\LaravelZero\Context\ZeroContextResolver;
use NickWelsh\LaravelZero\Protocol\ZeroMutationProcessor;

final readonly class ZeroMutateEndpoint
{
    public function __construct(private ZeroMutationProcessor $processor, private ZeroContextResolver $contexts) {}

    public function __invoke(Request $request): JsonResponse
    {
        $context = $this->contexts->resolve($request);
        $field = config('laravel-zero.context.user_id_field', 'user_id');
        $userID = isset($context->{$field}) ? (string) $context->{$field} : null;

        return response()->json($this->processor->process($request->json()->all(), $context, $userID, (string) $request->query('schema', 'public')));
    }
}
