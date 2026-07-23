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
                    $connection->table($this->metadataTable($schema, 'mutations'))->insert([
                        'clientGroupID' => $clientGroupID, 'clientID' => $clientID,
                        'mutationID' => $id, 'result' => json_encode($result, JSON_THROW_ON_ERROR),
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
        $table = $connection->table($this->metadataTable($schema, 'clients'));
        $key = ['clientGroupID' => $clientGroupID, 'clientID' => $clientID];
        $lastMutationID = $connection->getQueryGrammar()->wrap('lastMutationID');

        $table->upsert(
            [[...$key, 'lastMutationID' => 1]],
            ['clientGroupID', 'clientID'],
            ['lastMutationID' => $connection->raw("{$lastMutationID} + 1")],
        );

        $last = (int) $table->where($key)->value('lastMutationID');
        if ($received < $last) {
            throw new AlreadyProcessedMutation("Mutation {$received} already processed; expected {$last}.");
        }
        if ($received > $last) {
            throw new OutOfOrderMutation("Client {$clientID} sent mutation ID {$received} but expected {$last}");
        }
    }

    /** @param array<string, mixed> $args */
    private function cleanup(string $schema, string $clientGroupID, array $args): void
    {
        $query = $this->connection()->table($this->metadataTable($schema, 'mutations'))->where('clientGroupID', $clientGroupID);
        if (($args['type'] ?? 'single') === 'bulk') {
            $query->whereIn('clientID', $args['clientIDs'] ?? [])->delete();
        } else {
            $query->where('clientID', $args['clientID'] ?? '')->where('mutationID', '<=', $args['upToMutationID'] ?? 0)->delete();
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

    private function metadataTable(string $schema, string $table): string
    {
        return "{$schema}.{$table}";
    }
}
