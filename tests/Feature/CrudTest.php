<?php

declare(strict_types=1);

namespace ZeroForLaravel\Tests\Feature;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use ZeroForLaravel\Metadata\ModelRegistry;
use ZeroForLaravel\Mutation\CrudExecutor;
use ZeroForLaravel\Tests\Fixtures\Task;
use ZeroForLaravel\Tests\TestCase;
use ZeroForLaravel\ZeroContext;

uses(TestCase::class);

function crudExecutor(TestCase $test): CrudExecutor
{
    Gate::policy(Task::class, TaskPolicy::class);

    return new CrudExecutor(new ModelRegistry($test->models()));
}

function crudUser(string $id = 'alice'): User
{
    $user = new User;
    $user->setKeyType('string');
    $user->forceFill(['id' => $id]);

    return $user;
}

test('create fills ownership defaults and authoritative timestamps', function (): void {
    /** @var TestCase $this */
    crudExecutor($this)->execute('Task.create', ['id' => '019a0000-0000-7000-8000-000000000001', 'data' => ['title' => 'Hello'], 'now' => 1], new ZeroContext(['user_id' => 'alice']), crudUser());
    $task = Task::firstOrFail();
    $this->assertSame('alice', $task->user_id);
    $this->assertSame('Hello', $task->title);
    $this->assertFalse($task->done);
    $this->assertGreaterThan(1, $task->created_at->getTimestampMs());
});

test('updates are patches and dates round trip through eloquent', function (): void {
    /** @var TestCase $this */
    $task = Task::create(['user_id' => 'alice', 'title' => 'Original', 'done' => true]);
    crudExecutor($this)->execute('Task.update', ['id' => $task->id, 'data' => ['completed_at' => 1700000000123], 'now' => 1], new ZeroContext(['user_id' => 'alice']), crudUser());
    $task->refresh();
    $this->assertSame('Original', $task->title);
    $this->assertTrue($task->done);
    $this->assertNotNull($task->completed_at);
    $this->assertSame(1700000000000, $task->completed_at->getTimestampMs());
});

test('authorizes existing owner before applying an update', function (): void {
    /** @var TestCase $this */
    $task = Task::create(['user_id' => 'bob', 'title' => 'Private']);
    crudExecutor($this)->execute('Task.update', ['id' => $task->id, 'data' => ['title' => 'Claimed'], 'now' => 1], new ZeroContext(['user_id' => 'alice']), crudUser());
})->throws(AuthorizationException::class);

test('server fills and timestamps are not public inputs', function (): void {
    /** @var TestCase $this */
    crudExecutor($this)->execute('Task.create', ['id' => '019a0000-0000-7000-8000-000000000001', 'data' => ['title' => 'Hello', 'user_id' => 'bob'], 'now' => 1], new ZeroContext(['user_id' => 'alice']), crudUser());
})->throws(ValidationException::class);

test('soft delete restore and force delete use eloquent lifecycle', function (): void {
    /** @var TestCase $this */
    $task = Task::create(['user_id' => 'alice', 'title' => 'Hello']);
    $executor = crudExecutor($this);
    $args = ['id' => $task->id, 'data' => (object) [], 'now' => 1];
    $context = new ZeroContext(['user_id' => 'alice']);
    $executor->execute('Task.destroy', $args, $context, crudUser());
    $this->assertTrue(Task::withTrashed()->findOrFail($task->id)->trashed());
    $executor->execute('Task.restore', $args, $context, crudUser());
    $this->assertFalse(Task::withTrashed()->findOrFail($task->id)->trashed());
    $executor->execute('Task.force_delete', $args, $context, crudUser());
    $this->assertNull(Task::withTrashed()->find($task->id));
});

test('mutations fail closed without an allowing policy', function (): void {
    /** @var TestCase $this */
    $executor = new CrudExecutor(new ModelRegistry($this->models()));
    $executor->execute('Task.create', ['id' => '019a0000-0000-7000-8000-000000000001', 'data' => ['title' => 'Hello'], 'now' => 1], new ZeroContext(['user_id' => 'alice']), crudUser());
})->throws(AuthorizationException::class);

class TaskPolicy
{
    public function create(User $user): bool
    {
        return $user->getAuthIdentifier() === 'alice';
    }

    public function update(User $user, Task $task): bool
    {
        return $task->user_id === $user->getAuthIdentifier();
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
