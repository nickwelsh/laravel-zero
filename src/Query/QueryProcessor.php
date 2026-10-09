<?php

declare(strict_types=1);

namespace LaravelZero\Query;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use LaravelZero\Support\Input;
use LaravelZero\ZeroContext;

/**
 * @phpstan-import-type Ast from QueryCompiler
 *
 * @phpstan-type QueryResult array{id: string, name: string, ast?: Ast, error?: 'parse'|'app', message?: string, details?: array{fields: array<array-key, mixed>}}
 * @phpstan-type TransformFailed array{kind: 'TransformFailed', origin: 'server', reason: 'parse', message: string, queryIDs: list<string>}
 * @phpstan-type QueryResponse array{kind: 'QueryResponse', queries: list<QueryResult>, userID: string|null}
 */
final readonly class QueryProcessor
{
    public function __construct(private QueryCompiler $compiler) {}

    /**
     * @return TransformFailed|QueryResponse
     */
    public function process(mixed $body, ZeroContext $context): array
    {
        if (! is_array($body) || ! array_is_list($body) || count($body) !== 2 || $body[0] !== 'transform' || ! is_array($body[1]) || ! array_is_list($body[1]) || count($body[1]) > 100) {
            return ['kind' => 'TransformFailed', 'origin' => 'server', 'reason' => 'parse', 'message' => 'Invalid transform envelope.', 'queryIDs' => []];
        }

        $requests = [];
        try {
            foreach ($body[1] as $value) {
                $request = Input::object($value, 'query');
                Input::keys($request, ['id', 'name', 'args'], 'query');
                if (! is_string($request['id'] ?? null) || ! is_string($request['name'] ?? null) || ! is_array($request['args'] ?? null) || ! array_is_list($request['args'])) {
                    Input::fail('query', 'Invalid query invocation.');
                }

                $requests[] = $request;
            }
        } catch (ValidationException) {
            return ['kind' => 'TransformFailed', 'origin' => 'server', 'reason' => 'parse', 'message' => 'Invalid query invocation.', 'queryIDs' => []];
        }

        $queries = [];
        foreach ($requests as $request) {
            $result = ['id' => $request['id'], 'name' => $request['name']];
            try {
                if (count($request['args']) !== 1) {
                    Input::fail('args', 'Expected one argument object.');
                }

                $result['ast'] = $this->compiler->compile($request['name'], $request['args'][0], $context);
            } catch (ValidationException $error) {
                $result += ['error' => 'parse', 'message' => 'Invalid query arguments.', 'details' => ['fields' => $error->errors()]];
            } catch (AuthorizationException) {
                $result += ['error' => 'app', 'message' => 'This query is not authorized.'];
            }

            $queries[] = $result;
        }

        return ['kind' => 'QueryResponse', 'queries' => $queries, 'userID' => $context->userId()];
    }
}
