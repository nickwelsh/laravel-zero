<?php

namespace LaravelZero\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use JsonException;
use LaravelZero\Mutation\MutationProcessor;
use LaravelZero\Query\QueryProcessor;
use LaravelZero\ZeroContext;

final class ZeroController
{
    public function query(Request $request, QueryProcessor $processor): JsonResponse
    {
        return response()->json($processor->process($this->body($request), $this->context($request)));
    }

    public function mutate(Request $request, MutationProcessor $processor): JsonResponse
    {
        return response()->json($processor->process($this->body($request), $request->query(), $this->context($request), $request->user()));
    }

    private function body(Request $request): mixed
    {
        try {
            return json_decode($request->getContent(), depth: 64, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }
    }

    private function context(Request $request): ZeroContext
    {
        return app(config('zero.context_resolver'))($request);
    }
}
