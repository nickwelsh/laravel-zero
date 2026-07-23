<?php

namespace NickWelsh\LaravelZero\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use NickWelsh\LaravelZero\Context\ZeroContextResolver;
use NickWelsh\LaravelZero\Protocol\ZeroMutationProcessor;
use Stringable;
use UnexpectedValueException;

final readonly class ZeroMutateEndpoint
{
    public function __construct(private ZeroMutationProcessor $processor, private ZeroContextResolver $contexts) {}

    public function __invoke(Request $request): JsonResponse
    {
        $context = $this->contexts->resolve($request);
        $userID = $this->userID($context);
        $schema = $request->query('schema');
        $appID = $request->query('appID');

        if (! is_string($schema) || $schema === '' || ! is_string($appID) || $appID === '') {
            $mutations = $request->input('mutations', []);

            return response()->json([
                'kind' => 'PushFailed',
                'origin' => 'server',
                'reason' => 'parse',
                'message' => 'Zero mutate requires schema and appID query parameters.',
                'mutationIDs' => array_map(fn (mixed $mutation): array => [
                    'id' => is_array($mutation) ? ($mutation['id'] ?? 0) : 0,
                    'clientID' => is_array($mutation) ? ($mutation['clientID'] ?? '') : '',
                ], is_array($mutations) ? $mutations : []),
            ]);
        }

        return response()->json($this->processor->process($request->json()->all(), $context, $userID, $schema));
    }

    private function userID(object $context): ?string
    {
        $field = config('laravel-zero.context.user_id_field', 'user_id');

        if (! is_string($field) || $field === '') {
            throw new UnexpectedValueException('Zero context user ID field must be a non-empty string.');
        }

        if (! isset($context->{$field})) {
            return null;
        }

        $value = $context->{$field};

        if (! is_scalar($value) && ! $value instanceof Stringable) {
            throw new UnexpectedValueException("Zero context user ID [{$field}] must be stringable.");
        }

        return (string) $value;
    }
}
