<?php

declare(strict_types=1);

namespace LaravelZero\Tests\Feature;

use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use LaravelZero\Metadata\ModelRegistry;
use LaravelZero\Mutation\CrudExecutor;
use LaravelZero\Mutation\MutationProcessor;
use LaravelZero\Tests\Fixtures\Task;
use LaravelZero\Tests\PostgresTestCase;
use LaravelZero\ZeroContext;

uses(PostgresTestCase::class);

test('cancelled eloquent events are durable rejections', function (): void {
    /** @var PostgresTestCase $this */
    Task::saving(fn (): false => false);
    try {
        $result = mutationProtocolProcess($this, [mutationProtocolMutation($this, 1)]);
        $this->assertSame('app', $result['mutations'][0]['result']['error']);
        $this->assertSame(0, Task::count());
        $this->assertSame(1, (int) DB::table('zero_test_0.clients')->value('lastMutationID'));
        $this->assertSame(1, DB::table('zero_test_0.mutations')->count());
    } finally {
        Task::flushEventListeners();
    }
});

/**
 * @param  list<array<string, mixed>>  $mutations
 * @param  array<string, mixed>|null  $params
 * @return array<string, mixed>
 */
function mutationProtocolProcess(PostgresTestCase $test, array $mutations, ?array $params = null): array
{
    $user = (new User)->setKeyType('string');
    $user->forceFill(['id' => 'alice']);
    Gate::define('create', fn ($user, $model): true => true);
    $registry = new ModelRegistry($test->models());
    $processor = new MutationProcessor($registry, new CrudExecutor($registry), 'test', 'zero_test_0');

    return $processor->process([
        'clientGroupID' => 'group-a', 'pushVersion' => 1, 'timestamp' => 1,
        'requestID' => 'request-a', 'mutations' => $mutations,
    ], $params ?? ['appID' => 'test', 'schema' => 'zero_test_0'], new ZeroContext(['user_id' => 'alice']), $user);
}

/**
 * @param  array<string, mixed>  $data
 * @return array<string, mixed>
 */
function mutationProtocolMutation(PostgresTestCase $test, int $id, array $data = ['title' => 'Hello']): array
{
    return ['type' => 'custom', 'id' => $id, 'clientID' => 'client-a', 'name' => 'Task.create', 'timestamp' => 1,
        'args' => [['id' => '019a0000-0000-7000-8000-'.str_pad((string) $id, 12, '0', STR_PAD_LEFT), 'data' => (object) $data, 'now' => 1]]];
}

test('duplicates do not execute again and gaps do not advance progress', function (): void {
    /** @var PostgresTestCase $this */
    $result = mutationProtocolProcess($this, [mutationProtocolMutation($this, 1)]);
    $this->assertSame('MutateResponse', $result['kind']);
    $this->assertEquals((object) [], $result['mutations'][0]['result']);
    $result = mutationProtocolProcess($this, [mutationProtocolMutation($this, 1)]);
    $this->assertSame('alreadyProcessed', $result['mutations'][0]['result']['error']);
    $result = mutationProtocolProcess($this, [mutationProtocolMutation($this, 3)]);
    $this->assertSame('oooMutation', $result['reason']);
    $this->assertSame(1, Task::count());
    $this->assertSame(1, (int) DB::table('zero_test_0.clients')->value('lastMutationID'));
});

test('validation rejection is durable and does not block later mutations', function (): void {
    /** @var PostgresTestCase $this */
    $result = mutationProtocolProcess($this, [mutationProtocolMutation($this, 1, ['user_id' => 'bob']), mutationProtocolMutation($this, 2)]);
    $this->assertSame('app', $result['mutations'][0]['result']['error']);
    $this->assertArrayHasKey('data.user_id', $result['mutations'][0]['result']['details']['fields']);
    $this->assertSame(1, Task::count());
    $this->assertSame(2, (int) DB::table('zero_test_0.clients')->value('lastMutationID'));
    $this->assertSame(1, DB::table('zero_test_0.mutations')->count());
});

test('database failures roll back application writes and remain retryable', function (): void {
    /** @var PostgresTestCase $this */
    Task::created(function (): void {
        DB::statement('SELECT definitely_missing_function()');
    });
    $result = mutationProtocolProcess($this, [mutationProtocolMutation($this, 1)]);
    $this->assertSame('database', $result['reason']);
    $this->assertSame(0, Task::count());
    $this->assertSame(0, DB::table('zero_test_0.clients')->count());
    $this->assertSame(0, DB::table('zero_test_0.mutations')->count());
    Task::flushEventListeners();
    $this->assertSame('MutateResponse', mutationProtocolProcess($this, [mutationProtocolMutation($this, 1)])['kind']);
});

test('request schema and app are verified before accessing bookkeeping', function (): void {
    /** @var PostgresTestCase $this */
    $result = mutationProtocolProcess($this, [mutationProtocolMutation($this, 1)], ['schema' => 'public', 'appID' => 'other']);
    $this->assertSame('parse', $result['reason']);
    $this->assertSame(0, Task::count());
});

test('result cleanup is scoped to the current group', function (): void {
    /** @var PostgresTestCase $this */
    mutationProtocolProcess($this, [mutationProtocolMutation($this, 1, [])]);
    $cleanup = ['type' => 'custom', 'id' => 0, 'clientID' => 'client-a', 'name' => '_zero_cleanupResults', 'timestamp' => 1,
        'args' => [['clientGroupID' => 'group-a', 'clientID' => 'client-a', 'upToMutationID' => 1]]];
    $this->assertSame('MutateResponse', mutationProtocolProcess($this, [$cleanup])['kind']);
    $this->assertSame(0, DB::table('zero_test_0.mutations')->count());
    $this->assertSame(1, (int) DB::table('zero_test_0.clients')->value('lastMutationID'));
});
