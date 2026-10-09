<?php

declare(strict_types=1);

namespace LaravelZero\Mutation;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Validation\ValidationException;
use LaravelZero\Metadata\ModelRegistry;
use LaravelZero\Support\Input;
use LaravelZero\ZeroContext;
use Throwable;

final readonly class MutationProcessor
{
    public function __construct(private ModelRegistry $registry, private CrudExecutor $executor, private string $appId, private string $upstreamSchema) {}

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    public function process(mixed $body, array $params, ZeroContext $context, ?Authenticatable $user): array
    {
        try {
            $body = Input::object($body, 'push');
            if (($params['appID'] ?? null) !== $this->appId || ($params['schema'] ?? null) !== $this->upstreamSchema) {
                Input::fail('params', 'Unknown upstream app or schema.');
            }

            $this->identifier($body['clientGroupID'] ?? null, 'clientGroupID');
            $this->identifier($body['requestID'] ?? null, 'requestID');
            if (! is_int($body['pushVersion'] ?? null) || ! is_numeric($body['timestamp'] ?? null) || ! is_array($body['mutations'] ?? null) || ! array_is_list($body['mutations']) || count($body['mutations']) > 100) {
                Input::fail('push', 'Invalid push envelope.');
            }

            $mutations = [];
            foreach ($body['mutations'] as $value) {
                $mutation = Input::object($value, 'mutation');
                $this->identifier($mutation['clientID'] ?? null, 'clientID');
                $this->identifier($mutation['name'] ?? null, 'name');
                $minimum = $mutation['name'] === '_zero_cleanupResults' ? 0 : 1;
                if (($mutation['type'] ?? null) !== 'custom' || ! is_int($mutation['id'] ?? null) || $mutation['id'] < $minimum || $mutation['id'] > 9007199254740991 || ! is_numeric($mutation['timestamp'] ?? null) || ! is_array($mutation['args'] ?? null) || ! array_is_list($mutation['args'])) {
                    Input::fail('mutation', 'Invalid mutation invocation.');
                }

                $mutations[] = $mutation;
            }
        } catch (ValidationException) {
            return $this->failure('parse', [], 'Invalid push envelope or upstream configuration.');
        }

        if ($body['pushVersion'] !== 1) {
            return $this->failure('unsupportedPushVersion', $mutations, 'Only push version 1 is supported.');
        }

        $responses = [];
        foreach ($mutations as $index => $mutation) {
            try {
                if ($mutation['name'] === '_zero_cleanupResults') {
                    $this->cleanup($body['clientGroupID'], $mutation);

                    continue;
                }

                $responses[] = $this->apply($body['clientGroupID'], $mutation, $context, $user);
            } catch (OutOfOrder) {
                return $this->failure('oooMutation', array_slice($mutations, $index), 'Mutation is out of order.');
            } catch (QueryException $error) {
                report($error);

                return $this->failure('database', array_slice($mutations, $index), 'Database transaction failed; retry the push.');
            } catch (Throwable $error) {
                report($error);

                return $this->failure('internal', array_slice($mutations, $index), 'Mutation processing failed; retry the push.');
            }
        }

        return ['kind' => 'MutateResponse', 'mutations' => $responses, 'userID' => $context->userId()];
    }

    /**
     * @param  array<string, mixed>  $mutation
     * @return array<string, mixed>
     */
    private function apply(string $group, array $mutation, ZeroContext $context, ?Authenticatable $user): array
    {
        $id = ['clientID' => $mutation['clientID'], 'id' => $mutation['id']];
        $failure = null;
        try {
            try {
                return $this->registry->connection()->transaction(function () use ($group, $mutation, $context, $user, $id): array {
                    $this->advance($group, $mutation);
                    if (count($mutation['args']) !== 1) {
                        Input::fail('args', 'Expected one argument object.');
                    }

                    $this->executor->execute($mutation['name'], $mutation['args'][0], $context, $user);

                    return ['id' => $id, 'result' => (object) []];
                });
            } catch (ValidationException $error) {
                $failure = ['error' => 'app', 'message' => 'Validation failed.', 'details' => ['code' => 'validation', 'fields' => $error->errors()]];
            } catch (AuthorizationException) {
                $failure = ['error' => 'app', 'message' => 'This mutation is not authorized.', 'details' => ['code' => 'forbidden']];
            } catch (ModelNotFoundException) {
                $failure = ['error' => 'app', 'message' => 'The record is unavailable.', 'details' => ['code' => 'not_found']];
            } catch (QueryException $error) {
                if (! str_starts_with((string) $error->getCode(), '23')) {
                    throw $error;
                }

                $failure = ['error' => 'app', 'message' => 'A database constraint rejected the mutation.', 'details' => ['code' => 'constraint']];
            }

            // A failed application transaction is fully rolled back before its
            // rejection and progress are committed together in a new transaction.
            return $this->registry->connection()->transaction(function () use ($group, $mutation, $id, $failure): array {
                $this->advance($group, $mutation);
                $this->registry->connection()->table($this->upstreamSchema.'.mutations')->insert([
                    'clientGroupID' => $group, 'clientID' => $mutation['clientID'],
                    'mutationID' => $mutation['id'], 'result' => json_encode($failure, JSON_THROW_ON_ERROR),
                ]);

                return ['id' => $id, 'result' => $failure];
            });
        } catch (AlreadyProcessed) {
            return ['id' => $id, 'result' => ['error' => 'alreadyProcessed']];
        }
    }

    /**
     * @param  array<string, mixed>  $mutation
     */
    private function advance(string $group, array $mutation): void
    {
        $connection = $this->registry->connection();
        $schema = $connection->getQueryGrammar()->wrap($this->upstreamSchema);
        $row = $connection->selectOne('INSERT INTO '.$schema.'.clients AS current ("clientGroupID", "clientID", "lastMutationID") VALUES (?, ?, 1) ON CONFLICT ("clientGroupID", "clientID") DO UPDATE SET "lastMutationID" = current."lastMutationID" + 1 RETURNING "lastMutationID"', [$group, $mutation['clientID']]);
        $expected = (int) $row->lastMutationID;
        if ($mutation['id'] < $expected) {
            throw new AlreadyProcessed;
        }

        if ($mutation['id'] > $expected) {
            throw new OutOfOrder;
        }
    }

    /**
     * @param  array<string, mixed>  $mutation
     */
    private function cleanup(string $group, array $mutation): void
    {
        try {
            if (count($mutation['args']) !== 1) {
                return;
            }

            $args = Input::object($mutation['args'][0], 'cleanup');
            if (($args['clientGroupID'] ?? null) !== $group) {
                return;
            }

            $query = $this->registry->connection()->table($this->upstreamSchema.'.mutations')->where('clientGroupID', $group);
            if (($args['type'] ?? null) === 'bulk') {
                $ids = $args['clientIDs'] ?? null;
                if (! is_array($ids) || ! array_is_list($ids) || $ids === [] || count($ids) > 1000) {
                    return;
                }

                foreach ($ids as $id) {
                    $this->identifier($id, 'clientIDs');
                }

                $query->whereIn('clientID', $ids);
            } else {
                $this->identifier($args['clientID'] ?? null, 'clientID');
                if (! is_int($args['upToMutationID'] ?? null) || $args['upToMutationID'] < 0 || ($args['type'] ?? 'single') !== 'single') {
                    return;
                }

                $query->where('clientID', $args['clientID'])->where('mutationID', '<=', $args['upToMutationID']);
            }

            $query->delete();
        } catch (ValidationException) {
            // Like Zero's native handler, invalid internal cleanup is ignored.
        }
    }

    private function identifier(mixed $value, string $path): void
    {
        if (! is_string($value) || $value === '' || strlen($value) > 256) {
            Input::fail($path, 'Expected a non-empty identifier.');
        }
    }

    /**
     * @param  list<array<string, mixed>>  $mutations
     * @return array<string, mixed>
     */
    private function failure(string $reason, array $mutations, string $message): array
    {
        return ['kind' => 'PushFailed', 'origin' => 'server', 'reason' => $reason, 'message' => $message,
            'mutationIDs' => array_map(fn (array $m): array => ['clientID' => $m['clientID'], 'id' => $m['id']], $mutations)];
    }
}
