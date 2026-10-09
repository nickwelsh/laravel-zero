<?php

namespace LaravelZero\Tests\Feature;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use LaravelZero\Metadata\ModelRegistry;
use LaravelZero\Mutation\CrudExecutor;
use LaravelZero\Tests\Fixtures\Task;
use LaravelZero\Tests\TestCase;
use LaravelZero\ZeroContext;

class CrudTest extends TestCase
{
    private function executor(): CrudExecutor
    {
        Gate::policy(Task::class, TaskPolicy::class);

        return new CrudExecutor(new ModelRegistry($this->models()));
    }

    private function user(string $id = 'alice'): User
    {
        $user = new User;
        $user->setKeyType('string');
        $user->forceFill(['id' => $id]);

        return $user;
    }

    public function test_create_fills_ownership_defaults_and_authoritative_timestamps(): void
    {
        $this->executor()->execute('Task.create', ['id' => '019a0000-0000-7000-8000-000000000001', 'data' => ['title' => 'Hello'], 'now' => 1], new ZeroContext(['user_id' => 'alice']), $this->user());
        $task = Task::firstOrFail();
        self::assertSame('alice', $task->user_id);
        self::assertSame('Hello', $task->title);
        self::assertFalse($task->done);
        self::assertGreaterThan(1, $task->created_at->getTimestampMs());
    }

    public function test_updates_are_patches_and_dates_round_trip_through_eloquent(): void
    {
        $task = Task::create(['user_id' => 'alice', 'title' => 'Original', 'done' => true]);
        $this->executor()->execute('Task.update', ['id' => $task->id, 'data' => ['completed_at' => 1700000000123], 'now' => 1], new ZeroContext(['user_id' => 'alice']), $this->user());
        $task->refresh();
        self::assertSame('Original', $task->title);
        self::assertTrue($task->done);
        self::assertSame(1700000000000, $task->completed_at->getTimestampMs());
    }

    public function test_authorizes_existing_owner_before_applying_an_update(): void
    {
        $task = Task::create(['user_id' => 'bob', 'title' => 'Private']);
        $this->expectException(AuthorizationException::class);
        $this->executor()->execute('Task.update', ['id' => $task->id, 'data' => ['title' => 'Claimed'], 'now' => 1], new ZeroContext(['user_id' => 'alice']), $this->user());
    }

    public function test_server_fills_and_timestamps_are_not_public_inputs(): void
    {
        $this->expectException(ValidationException::class);
        $this->executor()->execute('Task.create', ['id' => '019a0000-0000-7000-8000-000000000001', 'data' => ['title' => 'Hello', 'user_id' => 'bob'], 'now' => 1], new ZeroContext(['user_id' => 'alice']), $this->user());
    }

    public function test_soft_delete_restore_and_force_delete_use_eloquent_lifecycle(): void
    {
        $task = Task::create(['user_id' => 'alice', 'title' => 'Hello']);
        $executor = $this->executor();
        $args = ['id' => $task->id, 'data' => (object) [], 'now' => 1];
        $context = new ZeroContext(['user_id' => 'alice']);
        $executor->execute('Task.destroy', $args, $context, $this->user());
        self::assertTrue($task->fresh()->trashed());
        $executor->execute('Task.restore', $args, $context, $this->user());
        self::assertFalse($task->fresh()->trashed());
        $executor->execute('Task.force_delete', $args, $context, $this->user());
        self::assertNull(Task::withTrashed()->find($task->id));
    }

    public function test_mutations_fail_closed_without_an_allowing_policy(): void
    {
        $executor = new CrudExecutor(new ModelRegistry($this->models()));
        $this->expectException(AuthorizationException::class);
        $executor->execute('Task.create', ['id' => '019a0000-0000-7000-8000-000000000001', 'data' => ['title' => 'Hello'], 'now' => 1], new ZeroContext(['user_id' => 'alice']), $this->user());
    }
}

class TaskPolicy
{
    public function create(User $user): bool
    {
        return $user->id === 'alice';
    }

    public function update(User $user, Task $task): bool
    {
        return $task->user_id === $user->id;
    }

    public function delete(User $user, Task $task): bool
    {
        return $this->update($user, $task);
    }

    public function restore(User $user, Task $task): bool
    {
        return $this->update($user, $task);
    }

    public function forceDelete(User $user, Task $task): bool
    {
        return $this->update($user, $task);
    }
}
