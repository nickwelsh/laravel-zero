<?php

namespace NickWelsh\LaravelZero\Protocol;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\DatabaseManager;
use NickWelsh\LaravelZero\Compiler\Arguments\ArgumentShape;
use NickWelsh\LaravelZero\Discovery\ZeroRegistry;
use Stringable;
use Throwable;
use UnexpectedValueException;
use UnitEnum;

final readonly class ZeroMutationProcessor
{
    public function __construct(private DatabaseManager $database, private ZeroRegistry $registry) {}

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    public function process(array $body, object $context, ?string $userID, string $schema): array
    {
        $pushVersion = $body['pushVersion'] ?? null;
        if ($pushVersion !== 1) {
            $version = match (true) {
                $pushVersion === null => 'missing',
                is_scalar($pushVersion), $pushVersion instanceof Stringable => (string) $pushVersion,
                default => get_debug_type($pushVersion),
            };

            return $this->failed('unsupportedPushVersion', 'Unsupported push version: '.$version, $this->mutationList($body['mutations'] ?? null));
        }
        if (! isset($body['clientGroupID']) || ! is_string($body['clientGroupID']) || ! isset($body['requestID']) || ! is_string($body['requestID']) || ! is_numeric($body['timestamp'] ?? null) || ! isset($body['mutations']) || ! is_array($body['mutations'])) {
            return $this->failed('parse', 'Invalid mutate request.', $this->mutationList($body['mutations'] ?? null));
        }

        $responses = [];
        $mutations = array_values($body['mutations']);
        foreach ($mutations as $index => $mutation) {
            if (! is_array($mutation) || ($mutation['type'] ?? null) !== 'custom') {
                return $this->failed('parse', 'Only custom mutations are supported.', array_slice($mutations, $index));
            }
            if (($mutation['name'] ?? null) === '_zero_cleanupResults') {
                $args = $mutation['args'] ?? null;
                $cleanupArgs = is_array($args) && isset($args[0]) && is_array($args[0]) ? $args[0] : [];
                $this->cleanup($schema, $body['clientGroupID'], $cleanupArgs);

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

    /**
     * @param  array<array-key, mixed>  $mutation
     * @return array<string, mixed>
     */
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
        $grammar = $table->getGrammar();
        /** @var non-falsy-string&literal-string $lastMutationID The grammar safely wraps both fixed identifiers. */
        $lastMutationID = $grammar->wrapTable('clients').'.'.$grammar->wrap('lastMutationID');

        $table->upsert(
            [[...$key, 'lastMutationID' => 1]],
            ['clientGroupID', 'clientID'],
            ['lastMutationID' => $connection->raw("{$lastMutationID} + 1")],
        );

        /** @var int|numeric-string $lastValue The metadata column is a non-null integer. */
        $lastValue = $table->where($key)->value('lastMutationID');
        $last = (int) $lastValue;
        if ($received < $last) {
            throw new AlreadyProcessedMutation("Mutation {$received} already processed; expected {$last}.");
        }
        if ($received > $last) {
            throw new OutOfOrderMutation("Client {$clientID} sent mutation ID {$received} but expected {$last}");
        }
    }

    /** @param array<array-key, mixed> $args */
    private function cleanup(string $schema, string $clientGroupID, array $args): void
    {
        $query = $this->connection()->table($this->metadataTable($schema, 'mutations'))->where('clientGroupID', $clientGroupID);
        if (($args['type'] ?? 'single') === 'bulk') {
            $query->whereIn('clientID', $args['clientIDs'] ?? [])->delete();
        } else {
            $query->where('clientID', $args['clientID'] ?? '')->where('mutationID', '<=', $args['upToMutationID'] ?? 0)->delete();
        }
    }

    /**
     * @param  list<mixed>  $mutations
     * @return array<string, mixed>
     */
    private function failed(string $reason, string $message, array $mutations): array
    {
        return ['kind' => 'PushFailed', 'origin' => 'server', 'reason' => $reason, 'message' => $message, 'mutationIDs' => array_map(
            fn (mixed $mutation): array => ['id' => is_array($mutation) ? ($mutation['id'] ?? 0) : 0, 'clientID' => is_array($mutation) ? ($mutation['clientID'] ?? '') : ''],
            $mutations,
        )];
    }

    /** @return list<mixed> */
    private function mutationList(mixed $mutations): array
    {
        return is_array($mutations) ? array_values($mutations) : [];
    }

    private function connection(): ConnectionInterface
    {
        $name = config('laravel-zero.database.connection');
        if ($name !== null && ! is_string($name) && ! $name instanceof UnitEnum) {
            throw new UnexpectedValueException('The Zero database connection must be a string, enum, or null.');
        }

        return $this->database->connection($name);
    }

    private function metadataTable(string $schema, string $table): string
    {
        return "{$schema}.{$table}";
    }
}
