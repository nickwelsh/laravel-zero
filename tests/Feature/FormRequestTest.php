<?php

declare(strict_types=1);

namespace ZeroForLaravel\Tests\Feature;

use Illuminate\Foundation\Auth\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use ZeroForLaravel\Definition\ZeroDefinition;
use ZeroForLaravel\Generation\TypeScriptGenerator;
use ZeroForLaravel\Metadata\ModelRegistry;
use ZeroForLaravel\Mutation\CrudExecutor;
use ZeroForLaravel\Tests\Fixtures\Task;
use ZeroForLaravel\Tests\TestCase;
use ZeroForLaravel\ZeroContext;

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

class FieldPermissionTask extends Task
{
    public static function zero(): ZeroDefinition
    {
        return ZeroDefinition::make()->mutationRequests(['update' => UpdatePermissionTask::class]);
    }
}

class UpdatePermissionTask extends FormRequest
{
    public function authorize(): bool
    {
        $task = $this->route('model');
        $user = $this->user();
        if (! $task instanceof Task || $user === null) {
            return false;
        }

        $allowed = $task->user_id === $user->getAuthIdentifier() ? ['title', 'completed_at'] : ['completed_at'];

        return array_diff(array_keys($this->except('id')), $allowed) === [];
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return ['title' => ['sometimes', 'required', 'string'], 'completed_at' => ['sometimes', 'nullable', 'date']];
    }
}

test('policies authorize the original task and form requests restrict the submitted fields', function (): void {
    Gate::define('update', fn (?\Illuminate\Contracts\Auth\Authenticatable $user): bool => $user instanceof \Illuminate\Contracts\Auth\Authenticatable && in_array($user->getAuthIdentifier(), ['alice', 'collaborator'], true));
    $task = FieldPermissionTask::create(['user_id' => 'alice', 'title' => 'Original']);
    $executor = new CrudExecutor(new ModelRegistry([FieldPermissionTask::class]));
    $collaborator = (new User)->setKeyType('string')->forceFill(['id' => 'collaborator']);
    $owner = (new User)->setKeyType('string')->forceFill(['id' => 'alice']);
    $context = new ZeroContext(['user_id' => 'collaborator']);
    app()->instance('request', Request::create('/zero/mutate', 'POST'));
    // The HTTP controller has a real route, which the operation request clones.
    $route = new \Illuminate\Routing\Route('POST', '/zero/mutate', fn (): null => null);
    $route->bind(resolve('request'));

    resolve('request')->setRouteResolver(fn (): \Illuminate\Routing\Route => $route);
    $executor->execute('FieldPermissionTask.update', ['id' => $task->id, 'data' => ['completed_at' => 1700000000000], 'now' => 1], $context, $collaborator);
    expect($task->fresh()?->title)->toBe('Original');
    expect($task->fresh()?->completed_at)->not->toBeNull();
    expect(fn () => $executor->execute('FieldPermissionTask.update', ['id' => $task->id, 'data' => ['title' => 'Forged', 'completed_at' => null], 'now' => 1], $context, $collaborator))->toThrow(\Illuminate\Auth\Access\AuthorizationException::class);
    expect($task->fresh()?->title)->toBe('Original');
    expect($task->fresh()?->completed_at)->not->toBeNull();

    $executor->execute('FieldPermissionTask.update', ['id' => $task->id, 'data' => ['title' => 'Owner edit'], 'now' => 1], new ZeroContext(['user_id' => 'alice']), $owner);
    expect($task->fresh()?->title)->toBe('Owner edit');
    $stranger = (new User)->setKeyType('string')->forceFill(['id' => 'stranger']);
    expect(fn () => $executor->execute('FieldPermissionTask.update', ['id' => $task->id, 'data' => ['completed_at' => null], 'now' => 1], $context, $stranger))->toThrow(\Illuminate\Auth\Access\AuthorizationException::class);
});

class JsonInputTask extends Task
{
    protected $fillable = ['title', 'options'];

    public static function zero(): ZeroDefinition
    {
        return ZeroDefinition::make()->mutationRequests(['create' => StoreJsonTask::class]);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['done' => 'boolean', 'options' => 'array'];
    }
}

class StoreJsonTask extends FormRequest
{
    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return ['title' => ['required', 'string'], 'options' => ['required', 'array', 'max:2'], 'options.theme' => ['required', 'in:dark,light']];
    }
}

test('JSON casts retain nested server validation and avoid double encoding text storage', function (): void {
    \Illuminate\Support\Facades\Schema::table('tasks', function (\Illuminate\Database\Schema\Blueprint $table): void {
        $table->text('options')->nullable();
    });
    Gate::define('create', fn (): bool => true);
    $registry = new ModelRegistry([JsonInputTask::class]);
    expect(implode(' ', $registry->get('JsonInputTask')->diagnostics))->toContain('nested JSON validation');
    $executor = new CrudExecutor($registry);
    $user = (new User)->setKeyType('string')->forceFill(['id' => 'alice']);
    $context = new ZeroContext(['user_id' => 'alice']);
    $executor->execute('JsonInputTask.create', ['id' => '019a0000-0000-7000-8000-000000000010', 'data' => ['title' => 'JSON', 'options' => '{"theme":"dark"}'], 'now' => 1], $context, $user);
    expect(JsonInputTask::firstOrFail()->getAttribute('options'))->toBe(['theme' => 'dark']);
    expect(fn () => $executor->execute('JsonInputTask.create', ['id' => '019a0000-0000-7000-8000-000000000011', 'data' => ['title' => 'JSON', 'options' => '{"theme":"invalid"}'], 'now' => 1], $context, $user))->toThrow(ValidationException::class);
    expect(fn () => $executor->execute('JsonInputTask.create', ['id' => '019a0000-0000-7000-8000-000000000012', 'data' => ['title' => 'JSON', 'options' => '{broken'], 'now' => 1], $context, $user))->toThrow(ValidationException::class);
    expect(JsonInputTask::count())->toBe(1);
});
