<?php

namespace NickWelsh\LaravelZero\Protocol;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\DatabaseManager;
use NickWelsh\LaravelZero\Compiler\Arguments\ArgumentShape;
use NickWelsh\LaravelZero\Discovery\ZeroRegistry;
use Throwable;

final readonly class ZeroMutationProcessor
{
    public function __construct(private DatabaseManager $database, private ZeroRegistry $registry) {}

    /** @param array<string, mixed> $body @return array<string, mixed> */
    public function process(array $body, object $context, ?string $userID, string $schema): array
    {
        if (($body['pushVersion'] ?? null) !== 1) {
            return $this->failed('unsupportedPushVersion', 'Unsupported push version: '.($body['pushVersion'] ?? 'missing'), $body['mutations'] ?? []);
        }
        if (! isset($body['clientGroupID']) || ! is_string($body['clientGroupID']) || ! isset($body['requestID']) || ! is_string($body['requestID']) || ! is_numeric($body['timestamp'] ?? null) || ! isset($body['mutations']) || ! is_array($body['mutations'])) {
            return $this->failed('parse', 'Invalid mutate request.', $body['mutations'] ?? []);
        }

        $responses = [];
        $mutations = array_values($body['mutations']);
        foreach ($mutations as $index => $mutation) {
            if (! is_array($mutation) || ($mutation['type'] ?? null) !== 'custom') {
                return $this->failed('parse', 'Only custom mutations are supported.', array_slice($mutations, $index));
            }
            if (($mutation['name'] ?? null) === '_zero_cleanupResults') {
                $this->cleanup($schema, $body['clientGroupID'], $mutation['args'][0] ?? []);

                continue;
            }
            try {
                $responses[] = $this->run($schema, $body['clientGroupID'], $mutation, $context);
            } catch (OutOfOrderMutation $error) {
                return $this->failed('oooMutation', $error->getMessage(), array_slice($mutations, $index));
            } catch (DatabaseMutationFailure $error) {
                return $this->failed('database', $error->getMessage(), array_slice($mutations, $index));
            }
        }

        return ['kind' => 'MutateResponse', 'userID' => $userID, 'mutations' => $responses];
    }

    /** @param array<string, mixed> $mutation @return array<string, mixed> */
    private function run(string $schema, string $clientGroupID, array $mutation, object $context): array
    {
        $clientID = $mutation['clientID'] ?? null;
        $id = $mutation['id'] ?? null;
        if (! is_string($clientID) || ! is_int($id) || ! is_string($mutation['name'] ?? null) || ! is_array($mutation['args'] ?? null) || ! array_is_list($mutation['args']) || ! is_numeric($mutation['timestamp'] ?? null)) {
            throw new OutOfOrderMutation('Malformed mutation.');
        }
        $identity = ['clientID' => $clientID, 'id' => $id];

        try {
            return $this->connection()->transaction(function (ConnectionInterface $connection) use ($schema, $clientGroupID, $mutation, $context, $identity, $clientID, $id): array {
                $this->advance($connection, $schema, $clientGroupID, $clientID, $id);
                $operation = $this->registry->mutation($mutation['name']);
                $arguments = ArgumentShape::from($operation->method)->hydrate($mutation['args']);
                $operation->method->invokeArgs($operation->instance(), [$context, ...$arguments]);

                return ['id' => $identity, 'result' => (object) []];
            });
        } catch (AlreadyProcessedMutation $error) {
            return ['id' => $identity, 'result' => ['error' => 'alreadyProcessed', 'details' => $error->getMessage()]];
        } catch (OutOfOrderMutation $error) {
            throw $error;
        } catch (Throwable $error) {
            $result = ['error' => 'app', 'message' => $error->getMessage(), 'details' => ['type' => $error::class]];
            try {
                $this->connection()->transaction(function (ConnectionInterface $connection) use ($schema, $clientGroupID, $clientID, $id, $result): void {
                    $this->advance($connection, $schema, $clientGroupID, $clientID, $id);
                    $connection->table($this->resultsTable())->insert([
                        'upstream_schema' => $schema, 'client_group_id' => $clientGroupID, 'client_id' => $clientID,
                        'mutation_id' => $id, 'result' => json_encode($result, JSON_THROW_ON_ERROR),
                    ]);
                });
            } catch (AlreadyProcessedMutation) {
                return ['id' => $identity, 'result' => ['error' => 'alreadyProcessed']];
            } catch (Throwable $persistenceError) {
                throw new DatabaseMutationFailure($persistenceError->getMessage(), previous: $persistenceError);
            }

            return ['id' => $identity, 'result' => $result];
        }
    }

    private function advance(ConnectionInterface $connection, string $schema, string $clientGroupID, string $clientID, int $received): void
    {
        $table = $this->clientsTable();
        $key = ['upstream_schema' => $schema, 'client_group_id' => $clientGroupID, 'client_id' => $clientID];
        $row = $connection->table($table)->where($key)->lockForUpdate()->first();
        $last = (int) ($row->last_mutation_id ?? 0);
        $expected = $last + 1;
        if ($received < $expected) {
            throw new AlreadyProcessedMutation("Mutation {$received} already processed; expected {$expected}.");
        }
        if ($received > $expected) {
            throw new OutOfOrderMutation("Client {$clientID} sent mutation ID {$received} but expected {$expected}");
        }
        if ($row) {
            $connection->table($table)->where($key)->update(['last_mutation_id' => $received]);
        } else {
            $connection->table($table)->insert([...$key, 'last_mutation_id' => $received]);
        }
    }

    /** @param array<string, mixed> $args */
    private function cleanup(string $schema, string $clientGroupID, array $args): void
    {
        $query = $this->connection()->table($this->resultsTable())->where('upstream_schema', $schema)->where('client_group_id', $clientGroupID);
        if (($args['type'] ?? 'single') === 'bulk') {
            $query->whereIn('client_id', $args['clientIDs'] ?? [])->delete();
        } else {
            $query->where('client_id', $args['clientID'] ?? '')->where('mutation_id', '<=', $args['upToMutationID'] ?? 0)->delete();
        }
    }

    /** @param list<mixed> $mutations @return array<string, mixed> */
    private function failed(string $reason, string $message, array $mutations): array
    {
        return ['kind' => 'PushFailed', 'origin' => 'server', 'reason' => $reason, 'message' => $message, 'mutationIDs' => array_values(array_map(
            fn (mixed $mutation): array => ['id' => is_array($mutation) ? ($mutation['id'] ?? 0) : 0, 'clientID' => is_array($mutation) ? ($mutation['clientID'] ?? '') : ''],
            $mutations,
        ))];
    }

    private function connection(): ConnectionInterface
    {
        return $this->database->connection(config('laravel-zero.database.connection'));
    }

    private function clientsTable(): string
    {
        return config('laravel-zero.database.clients_table', 'zero_clients') ?: 'zero_clients';
    }

    private function resultsTable(): string
    {
        return config('laravel-zero.database.mutation_results_table', 'zero_mutation_results') ?: 'zero_mutation_results';
    }
}
