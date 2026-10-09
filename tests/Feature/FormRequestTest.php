<?php

namespace LaravelZero\Tests\Feature;

use Illuminate\Foundation\Auth\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use LaravelZero\Definition\ZeroDefinition;
use LaravelZero\Generation\TypeScriptGenerator;
use LaravelZero\Metadata\ModelRegistry;
use LaravelZero\Mutation\CrudExecutor;
use LaravelZero\Tests\Fixtures\Task;
use LaravelZero\Tests\TestCase;
use LaravelZero\ZeroContext;

uses(TestCase::class);

test('generates portable validation and reports server only rules', function (): void {
        $registry = new ModelRegistry([ValidatedTask::class]);
        $source = (new TypeScriptGenerator)->generate($registry);
        $this->assertStringContainsString('max:12', $source);
        $this->assertStringContainsString('validation', $source);
        $this->assertNotEmpty($registry->get('ValidatedTask')->diagnostics);
    });

test('each form request sees its own data user and date values', function (): void {
        Gate::define('create', fn ($user, $model) => true);
        $user = (new User)->setKeyType('string');
        $user->forceFill(['id' => 'alice']);
        $executor = new CrudExecutor(new ModelRegistry([ValidatedTask::class]));
        $executor->execute('ValidatedTask.create', ['id' => '019a0000-0000-7000-8000-000000000001', 'data' => ['title' => '  Trim me  ', 'completed_at' => 1700000000000], 'now' => 1], new ZeroContext(['user_id' => 'alice']), $user);
        $this->assertSame('Trim me', Task::firstOrFail()->title);
        $this->assertSame(1700000000000, Task::firstOrFail()->completed_at->getTimestampMs());
    });

test('form request validation rejects invalid operation fields', function (): void {
        Gate::define('create', fn ($user, $model) => true);
        $user = (new User)->setKeyType('string');
        $user->forceFill(['id' => 'alice']);
        $this->expectException(ValidationException::class);
        (new CrudExecutor(new ModelRegistry([ValidatedTask::class])))->execute('ValidatedTask.create', ['id' => '019a0000-0000-7000-8000-000000000001', 'data' => ['title' => 'Far too long for the rules'], 'now' => 1], new ZeroContext(['user_id' => 'alice']), $user);
    });


class ValidatedTask extends Task
{
    public static function zero(): ZeroDefinition
    {
        return ZeroDefinition::make()->mutationRequests(['create' => StoreTask::class]);
    }
}

class StoreTask extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->getAuthIdentifier() === 'alice';
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['title' => trim($this->input('title', ''))]);
    }

    public function rules(): array
    {
        return ['title' => ['required', 'string', 'max:12'], 'completed_at' => ['nullable', 'date'], 'list_id' => ['nullable', 'exists:lists,id']];
    }
}
