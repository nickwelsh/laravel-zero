<?php

declare(strict_types=1);

namespace ZeroForLaravel\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use JsonException;
use LogicException;
use ZeroForLaravel\Mutation\MutationProcessor;
use ZeroForLaravel\Query\QueryProcessor;
use ZeroForLaravel\ZeroContext;

final class ZeroController
{
    public function query(Request $request, QueryProcessor $processor): JsonResponse
    {
        return response()->json($processor->process($this->body($request), $this->context($request)));
    }

    public function mutate(Request $request, MutationProcessor $processor): JsonResponse
    {
        return response()->json($processor->process($this->body($request), ['appID' => $request->query('appID'), 'schema' => $request->query('schema')], $this->context($request), $request->user()));
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
        $resolver = resolve(config()->string('zero.context_resolver'));
        if (! is_callable($resolver)) {
            throw new LogicException('Zero context resolver must be callable.');
        }

        $context = $resolver($request);
        if (! $context instanceof ZeroContext) {
            throw new LogicException('Zero context resolver must return ZeroContext.');
        }

        return $context;
    }
}
