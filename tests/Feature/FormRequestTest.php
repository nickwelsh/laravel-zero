<?php

declare(strict_types=1);

namespace LaravelZero\Tests\Feature;

use Illuminate\Foundation\Auth\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Request;
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
    /** @var TestCase $this */
    $registry = new ModelRegistry([ValidatedTask::class]);
    $source = (new TypeScriptGenerator)->generate($registry);
    $this->assertStringContainsString('max:12', $source);
    $this->assertStringContainsString('validation', $source);
    $this->assertNotEmpty($registry->get('ValidatedTask')->diagnostics);
});

test('each form request sees its own data user and date values', function (): void {
    /** @var TestCase $this */
    Gate::define('create', fn ($user, $model): true => true);
    $user = (new User)->setKeyType('string');
    $user->forceFill(['id' => 'alice']);

    $executor = new CrudExecutor(new ModelRegistry([ValidatedTask::class]));
    $executor->execute('ValidatedTask.create', ['id' => '019a0000-0000-7000-8000-000000000001', 'data' => ['title' => '  Trim me  ', 'completed_at' => 1700000000000], 'now' => 1], new ZeroContext(['user_id' => 'alice']), $user);
    $this->assertSame('Trim me', Task::firstOrFail()->title);
    $completed = Task::firstOrFail()->completed_at;
    $this->assertNotNull($completed);
    $this->assertSame(1700000000000, $completed->getTimestampMs());
});

test('form request validation rejects invalid operation fields', function (): void {
    Gate::define('create', fn ($user, $model): true => true);
    $user = (new User)->setKeyType('string');
    $user->forceFill(['id' => 'alice']);
    (new CrudExecutor(new ModelRegistry([ValidatedTask::class])))->execute('ValidatedTask.create', ['id' => '019a0000-0000-7000-8000-000000000001', 'data' => ['title' => 'Far too long for the rules'], 'now' => 1], new ZeroContext(['user_id' => 'alice']), $user);
})->throws(ValidationException::class);

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

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return ['title' => ['required', 'string', 'max:12'], 'completed_at' => ['nullable', 'date'], 'list_id' => ['nullable', 'exists:lists,id']];
    }

    protected function prepareForValidation(): void
    {
        $title = $this->input('title', '');
        if (is_string($title)) {
            $this->merge(['title' => trim($title)]);
        }
    }
}

class InvalidDateTask extends Task
{
    public static function zero(): ZeroDefinition
    {
        return ZeroDefinition::make()->mutationRequests(['create' => InvalidDateRequest::class]);
    }
}

class InvalidDateRequest extends StoreTask
{
    protected function passedValidation(): void
    {
        $this->merge(['completed_at' => []]);
    }
}

test('invalid normalized date values return validation errors', function (): void {
    Gate::define('create', fn (): true => true);
    $user = (new User)->setKeyType('string')->forceFill(['id' => 'alice']);
    (new CrudExecutor(new ModelRegistry([InvalidDateTask::class])))->execute('InvalidDateTask.create', [
        'id' => '019a0000-0000-7000-8000-000000000002', 'data' => ['title' => 'Hello'], 'now' => 1,
    ], new ZeroContext(['user_id' => 'alice']), $user);
})->throws(ValidationException::class, 'Invalid normalized date.');

class ServerRuleTask extends Task
{
    public static function zero(): ZeroDefinition
    {
        return ZeroDefinition::make()->mutationRequests(['create' => ServerRuleRequest::class]);
    }
}

class ServerRuleRequest extends FormRequest
{
    /** @return array<string, \Illuminate\Validation\Rules\Exists> */
    public function rules(): array
    {
        return ['list_id' => \Illuminate\Validation\Rule::exists('lists', 'id')];
    }
}

test('standalone rule objects remain server only', function (): void {
    /** @var TestCase $this */
    $metadata = (new ModelRegistry([ServerRuleTask::class]))->get('ServerRuleTask');
    $this->assertSame([], $metadata->validation['create']['list_id']);
    $this->assertCount(1, $metadata->diagnostics);
    $this->assertStringContainsString('server-only validation', $metadata->diagnostics[0]);
});

test('form requests isolate operation data from transport query parameters', function (): void {
    /** @var TestCase $this */
    Gate::define('create', fn (): true => true);
    app()->instance('request', Request::create('/zero/mutate?schema=todos_0&appID=todos&title=Forged', 'POST', ['mutations' => []]));
    $user = (new User)->setKeyType('string')->forceFill(['id' => 'alice']);
    (new CrudExecutor(new ModelRegistry([ValidatedTask::class])))->execute('ValidatedTask.create', [
        'id' => '019a0000-0000-7000-8000-000000000003', 'data' => ['title' => 'Actual'], 'now' => 1,
    ], new ZeroContext(['user_id' => 'alice']), $user);
    $this->assertSame('Actual', Task::firstOrFail()->title);
});
