<?php

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

class MutationProtocolTest extends PostgresTestCase
{
    public function test_cancelled_eloquent_events_are_durable_rejections(): void
    {
        Task::saving(fn () => false);
        try {
            $result = $this->process([$this->mutation(1)]);
            self::assertSame('app', $result['mutations'][0]['result']['error']);
            self::assertSame(0, Task::count());
            self::assertSame(1, (int) DB::table('zero_test_0.clients')->value('lastMutationID'));
            self::assertSame(1, DB::table('zero_test_0.mutations')->count());
        } finally {
            Task::flushEventListeners();
        }
    }

    private function process(array $mutations, ?array $params = null): array
    {
        $user = (new User)->setKeyType('string');
        $user->forceFill(['id' => 'alice']);
        Gate::define('create', fn ($user, $model) => true);
        $registry = new ModelRegistry($this->models());
        $processor = new MutationProcessor($registry, new CrudExecutor($registry), 'test', 'zero_test_0');

        return $processor->process([
            'clientGroupID' => 'group-a', 'pushVersion' => 1, 'timestamp' => 1,
            'requestID' => 'request-a', 'mutations' => $mutations,
        ], $params ?? ['appID' => 'test', 'schema' => 'zero_test_0'], new ZeroContext(['user_id' => 'alice']), $user);
    }

    private function mutation(int $id, array $data = ['title' => 'Hello']): array
    {
        return ['type' => 'custom', 'id' => $id, 'clientID' => 'client-a', 'name' => 'Task.create', 'timestamp' => 1,
            'args' => [['id' => '019a0000-0000-7000-8000-'.str_pad((string) $id, 12, '0', STR_PAD_LEFT), 'data' => (object) $data, 'now' => 1]]];
    }

    public function test_duplicates_do_not_execute_again_and_gaps_do_not_advance_progress(): void
    {
        $result = $this->process([$this->mutation(1)]);
        self::assertSame('MutateResponse', $result['kind']);
        self::assertEquals((object) [], $result['mutations'][0]['result']);
        $result = $this->process([$this->mutation(1)]);
        self::assertSame('alreadyProcessed', $result['mutations'][0]['result']['error']);
        $result = $this->process([$this->mutation(3)]);
        self::assertSame('oooMutation', $result['reason']);
        self::assertSame(1, Task::count());
        self::assertSame(1, (int) DB::table('zero_test_0.clients')->value('lastMutationID'));
    }

    public function test_validation_rejection_is_durable_and_does_not_block_later_mutations(): void
    {
        $result = $this->process([$this->mutation(1, ['user_id' => 'bob']), $this->mutation(2)]);
        self::assertSame('app', $result['mutations'][0]['result']['error']);
        self::assertArrayHasKey('data.user_id', $result['mutations'][0]['result']['details']['fields']);
        self::assertSame(1, Task::count());
        self::assertSame(2, (int) DB::table('zero_test_0.clients')->value('lastMutationID'));
        self::assertSame(1, DB::table('zero_test_0.mutations')->count());
    }

    public function test_database_failures_roll_back_application_writes_and_remain_retryable(): void
    {
        Task::created(function () {
            DB::statement('SELECT definitely_missing_function()');
        });
        $result = $this->process([$this->mutation(1)]);
        self::assertSame('database', $result['reason']);
        self::assertSame(0, Task::count());
        self::assertSame(0, DB::table('zero_test_0.clients')->count());
        self::assertSame(0, DB::table('zero_test_0.mutations')->count());
        Task::flushEventListeners();
        self::assertSame('MutateResponse', $this->process([$this->mutation(1)])['kind']);
    }

    public function test_request_schema_and_app_are_verified_before_accessing_bookkeeping(): void
    {
        $result = $this->process([$this->mutation(1)], ['schema' => 'public', 'appID' => 'other']);
        self::assertSame('parse', $result['reason']);
        self::assertSame(0, Task::count());
    }

    public function test_result_cleanup_is_scoped_to_the_current_group(): void
    {
        $this->process([$this->mutation(1, [])]);
        $cleanup = ['type' => 'custom', 'id' => 0, 'clientID' => 'client-a', 'name' => '_zero_cleanupResults', 'timestamp' => 1,
            'args' => [['clientGroupID' => 'group-a', 'clientID' => 'client-a', 'upToMutationID' => 1]]];
        self::assertSame('MutateResponse', $this->process([$cleanup])['kind']);
        self::assertSame(0, DB::table('zero_test_0.mutations')->count());
        self::assertSame(1, (int) DB::table('zero_test_0.clients')->value('lastMutationID'));
    }
}
