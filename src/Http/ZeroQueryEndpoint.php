<?php

namespace NickWelsh\LaravelZero\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use NickWelsh\LaravelZero\Compiler\Arguments\ArgumentShape;
use NickWelsh\LaravelZero\Compiler\Diagnostics\ZeroCompilerException;
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
        if (! array_is_list($payload) || count($payload) !== 2 || $payload[0] !== 'transform' || ! is_array($payload[1])) {
            return response()->json([
                'kind' => 'TransformFailed',
                'origin' => 'server',
                'reason' => 'parse',
                'message' => 'Expected Zero 1.8 transform request tuple.',
                'queryIDs' => [],
            ]);
        }
        $queryIDs = [];
        foreach ($payload[1] as $item) {
            if (is_array($item) && is_string($item['id'] ?? null)) {
                $queryIDs[] = $item['id'];
            }
            if (! is_array($item) || ! is_string($item['id'] ?? null) || ! is_string($item['name'] ?? null) || ! is_array($item['args'] ?? null) || ! array_is_list($item['args'])) {
                return response()->json([
                    'kind' => 'TransformFailed',
                    'origin' => 'server',
                    'reason' => 'parse',
                    'message' => 'Malformed Zero query request.',
                    'queryIDs' => $queryIDs,
                ]);
            }
        }
        $context = $this->contexts->resolve($request);
        $field = config('laravel-zero.context.user_id_field', 'user_id');
        $userID = isset($context->{$field}) ? (string) $context->{$field} : null;
        $responses = [];

        foreach ($payload[1] as $item) {
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
            } catch (ValidationException $error) {
                $responses[] = ['error' => 'parse', 'id' => $id, 'name' => $name, 'message' => $error->getMessage()];
            } catch (ZeroCompilerException $error) {
                $responses[] = ['error' => str_starts_with($error->diagnosticCode, 'ZERO-A') ? 'parse' : 'app', 'id' => $id, 'name' => $name, 'message' => $error->getMessage()];
            } catch (Throwable $error) {
                $responses[] = ['error' => 'app', 'id' => $id, 'name' => $name, 'message' => $error->getMessage()];
            }
        }

        return response()->json(['kind' => 'QueryResponse', 'userID' => $userID, 'queries' => $responses]);
    }
}
