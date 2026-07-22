<?php

namespace NickWelsh\LaravelZero\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use NickWelsh\LaravelZero\Compiler\Arguments\ArgumentShape;
use NickWelsh\LaravelZero\Context\ZeroContextResolver;
use NickWelsh\LaravelZero\Discovery\ZeroRegistry;
use NickWelsh\LaravelZero\Queries\ZeroQueryBuilder;
use Throwable;

final readonly class ZeroQueryEndpoint
{
    public function __construct(private ZeroRegistry $registry, private ZeroContextResolver $contexts) {}

    public function __invoke(Request $request): JsonResponse
    {
        $payload = $request->json()->all();
        if (! array_is_list($payload)) {
            return response()->json(['kind' => 'QueryFailed', 'reason' => 'parse', 'message' => 'Expected query request array.'], 400);
        }
        $context = $this->contexts->resolve($request);
        $responses = [];

        foreach ($payload as $item) {
            $id = $item['id'] ?? '';
            $name = $item['name'] ?? '';
            try {
                $operation = $this->registry->query($name);
                $arguments = ArgumentShape::from($operation->method)->hydrate($item['args'] ?? []);
                $result = $operation->method->invokeArgs($operation->instance(), [$context, ...$arguments]);
                if (! $result instanceof ZeroQueryBuilder) {
                    throw new \RuntimeException('Zero query must return ZeroQueryBuilder.');
                }
                $responses[] = ['id' => $id, 'name' => $name, 'ast' => $result->toAst()];
            } catch (Throwable $error) {
                $responses[] = ['error' => 'app', 'id' => $id, 'name' => $name, 'details' => ['message' => $error->getMessage()]];
            }
        }

        return response()->json($responses);
    }
}
