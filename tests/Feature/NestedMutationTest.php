<?php

declare(strict_types=1);

namespace LaravelZero\Tests\Feature;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Auth\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use LaravelZero\Attributes\ZeroMutate;
use LaravelZero\ContextField;
use LaravelZero\Definition\NestedMutation;
use LaravelZero\Definition\ZeroDefinition;
use LaravelZero\Generation\TypeScriptGenerator;
use LaravelZero\Metadata\ModelRegistry;
use LaravelZero\Mutation\CrudExecutor;
use LaravelZero\Mutation\MutationContext;
use LaravelZero\Tests\TestCase;
use LaravelZero\ZeroContext;
use RuntimeException;

uses(TestCase::class);

beforeEach(function (): void {
    Schema::table('lists', fn (Blueprint $table) => $table->uuid('host_id')->nullable());
    NestedParty::$mode = 'default';
    NestedPartyPolicy::$allow = true;
    NestedPartyPolicy::$allowRelation = true;
    NestedPersonPolicy::$allow = true;
    NestedPersonRequest::$allow = true;

    NestedPersonRequest::$rewriteKey = false;
    Gate::policy(NestedParty::class, NestedPartyPolicy::class);
    Gate::policy(NestedPerson::class, NestedPersonPolicy::class);
});

/**
 * @param  array<string, mixed>  $data
 * @param  array<string, mixed>  $relations
 * @return array<string, mixed>
 */
function nestedArgs(int $id, array $data = [], array $relations = []): array
{
    return ['id' => nestedId($id), 'data' => $data, 'now' => 1, 'relations' => $relations];
}

function nestedId(int $id): string
{
    return '019a0000-0000-7000-8000-'.str_pad((string) $id, 12, '0', STR_PAD_LEFT);
}

/**
 * @param  array<string, mixed>  ...$records
 * @return array{operation: string, records: list<array<string, mixed>>}
 */
function nestedRecords(string $operation, array ...$records): array
{
    return ['operation' => $operation, 'records' => array_values($records)];
}

/** @param array<string, mixed> $args */
function nestedExecute(string $operation, array $args): void
{
    $registry = new ModelRegistry([NestedParty::class, NestedPerson::class, NestedAddress::class]);
    $user = (new User)->setKeyType('string')->forceFill(['id' => 'alice']);
    (new CrudExecutor($registry))->execute('NestedParty.'.$operation, $args, new ZeroContext(['user_id' => 'alice']), $user);
}

test('nested creation uses both policies and fills keys without exposing standalone child mutations', function (): void {
    $registry = new ModelRegistry([NestedParty::class, NestedPerson::class, NestedAddress::class]);
    expect($registry->get('NestedPerson')->mutations)->toBe([])
        ->and($registry->get('NestedPerson')->validation['create']['title'])->toContain('required');
    $source = (new TypeScriptGenerator)->generate($registry);
    expect($source)->toContain('nestedMutations', '"kind": "hasOne"');
    expect(str_contains($source, 'addPerson'))->toBeFalse();
    nestedExecute('create', nestedArgs(1, ['name' => 'Party'], ['person' => nestedRecords('create', nestedArgs(2, ['title' => 'Person']))]));
    expect(NestedParty::findOrFail(nestedId(1))->name)->toBe('Party')
        ->and(NestedPerson::findOrFail(nestedId(2))->list_id)->toBe(nestedId(1));
});

test('denied child policy rolls back the created parent', function (): void {
    NestedPersonPolicy::$allow = false;
    expect(fn () => nestedExecute('create', nestedArgs(1, ['name' => 'Party'], ['person' => nestedRecords('create', nestedArgs(2, ['title' => 'Person']))])))->toThrow(AuthorizationException::class);
    expect(NestedParty::count())->toBe(0)->and(NestedPerson::count())->toBe(0);
});

test('parent policy is required even with inherited child authorization', function (): void {
    NestedParty::$mode = 'parent';
    NestedPartyPolicy::$allow = false;
    expect(fn () => nestedExecute('create', nestedArgs(1, ['name' => 'Party'], ['person' => nestedRecords('create', nestedArgs(2, ['title' => 'Person']))])))->toThrow(AuthorizationException::class);
    expect(NestedParty::count())->toBe(0);
});

test('authorizeUsingParent skips the child policy but preserves request authorization', function (): void {
    NestedParty::$mode = 'parent';
    NestedPersonPolicy::$allow = false;
    nestedExecute('create', nestedArgs(1, ['name' => 'Party'], ['person' => nestedRecords('create', nestedArgs(2, ['title' => 'Person']))]));
    expect(NestedPerson::count())->toBe(1);
    NestedPersonRequest::$allow = false;
    expect(fn () => nestedExecute('create', nestedArgs(3, ['name' => 'Other'], ['person' => nestedRecords('create', nestedArgs(4, ['title' => 'Denied']))])))->toThrow(AuthorizationException::class);
    expect(NestedParty::count())->toBe(1)->and(NestedPerson::count())->toBe(1);
});

test('relationship abilities are additional parent policy checks', function (): void {
    NestedParty::$mode = 'ability';
    NestedPartyPolicy::$allowRelation = false;
    expect(fn () => nestedExecute('create', nestedArgs(1, ['name' => 'Party'], ['person' => nestedRecords('create', nestedArgs(2, ['title' => 'Person']))])))->toThrow(AuthorizationException::class);
    expect(NestedParty::count())->toBe(0);

    NestedPartyPolicy::$allowRelation = true;
    nestedExecute('create', nestedArgs(1, ['name' => 'Party'], ['person' => nestedRecords('create', nestedArgs(2, ['title' => 'Person']))]));
    NestedPersonPolicy::$allow = false;
    expect(fn () => nestedExecute('update', nestedArgs(1, [], ['person' => nestedRecords('update', nestedArgs(2, ['title' => 'Changed']))])))->toThrow(AuthorizationException::class);
});

test('nested request errors use form paths and roll back parent and earlier siblings', function (): void {
    try {
        nestedExecute('create', nestedArgs(1, ['name' => 'Party'], ['people' => nestedRecords('create', nestedArgs(2, ['title' => 'Good']), nestedArgs(3, ['title' => str_repeat('x', 30)]))]));
        throw new RuntimeException('Expected validation failure.');
    } catch (ValidationException $validationException) {
        expect($validationException->errors())->toHaveKey('people.1.title');
    }

    expect(NestedParty::count())->toBe(0)->and(NestedPerson::count())->toBe(0);
});

test('nested updates verify membership and preserve atomic root changes', function (): void {
    nestedExecute('create', nestedArgs(1, ['name' => 'Party'], ['person' => nestedRecords('create', nestedArgs(2, ['title' => 'Person']))]));
    nestedExecute('create', nestedArgs(3, ['name' => 'Other'], ['person' => nestedRecords('create', nestedArgs(4, ['title' => 'Other person']))]));
    nestedExecute('update', nestedArgs(1, [], ['person' => nestedRecords('update', nestedArgs(2, ['title' => 'Changed']))]));
    expect(NestedPerson::findOrFail(nestedId(2))->title)->toBe('Changed');
    expect(fn () => nestedExecute('update', nestedArgs(1, ['name' => 'Forged'], ['person' => nestedRecords('update', nestedArgs(4, ['title' => 'Stolen']))])))->toThrow(ValidationException::class);
    expect(NestedParty::findOrFail(nestedId(1))->name)->toBe('Party')
        ->and(NestedPerson::findOrFail(nestedId(4))->title)->toBe('Other person');
});

test('linking keys cannot be supplied or rewritten by a request', function (): void {
    expect(fn () => nestedExecute('create', nestedArgs(1, ['name' => 'Party'], ['person' => nestedRecords('create', nestedArgs(2, ['title' => 'Person', 'list_id' => nestedId(3)]))])))->toThrow(ValidationException::class);
    NestedPersonRequest::$rewriteKey = true;
    expect(fn () => nestedExecute('create', nestedArgs(1, ['name' => 'Party'], ['person' => nestedRecords('create', nestedArgs(2, ['title' => 'Person']))])))->toThrow(ValidationException::class);
    expect(NestedParty::count())->toBe(0);
});

test('omitting relations leaves them alone and hasOne creation never replaces an existing child', function (): void {
    nestedExecute('create', nestedArgs(1, ['name' => 'Party'], ['person' => nestedRecords('create', nestedArgs(2, ['title' => 'Person']))]));
    nestedExecute('update', nestedArgs(1, ['name' => 'Renamed']));
    expect(NestedPerson::count())->toBe(1);
    expect(fn () => nestedExecute('update', nestedArgs(1, [], ['person' => nestedRecords('create', nestedArgs(3, ['title' => 'Replacement']))])))->toThrow(ValidationException::class);
    expect(NestedPerson::count())->toBe(1);
});

test('belongsTo dependencies are created first and cannot be replaced or updated through a forged id', function (): void {
    $order = [];
    NestedPerson::created(function () use (&$order): void {
        $order[] = 'person';
    });
    NestedParty::created(function () use (&$order): void {
        $order[] = 'party';
    });
    try {
        nestedExecute('create', nestedArgs(1, ['name' => 'Party'], ['host' => nestedRecords('create', nestedArgs(2, ['title' => 'Host']))]));
        expect($order)->toBe(['person', 'party']);
        expect(NestedParty::findOrFail(nestedId(1))->host_id)->toBe(nestedId(2));
        expect(fn () => nestedExecute('update', nestedArgs(1, [], ['host' => nestedRecords('create', nestedArgs(3, ['title' => 'Replacement']))])))->toThrow(ValidationException::class);
        expect(fn () => nestedExecute('update', nestedArgs(1, [], ['host' => nestedRecords('update', nestedArgs(3, ['title' => 'Forged']))])))->toThrow(ValidationException::class);
        nestedExecute('update', nestedArgs(1, [], ['host' => nestedRecords('update', nestedArgs(2, ['title' => 'Changed']))]));
        expect(NestedPerson::findOrFail(nestedId(2))->title)->toBe('Changed');
    } finally {
        NestedPerson::flushEventListeners();
        NestedParty::flushEventListeners();
    }
});

test('nested declarations do not enable standalone child operations', function (): void {
    $registry = new ModelRegistry([NestedParty::class, NestedPerson::class, NestedAddress::class]);
    expect(fn () => (new CrudExecutor($registry))->execute('NestedPerson.create', nestedArgs(1, ['title' => 'Person']), new ZeroContext(['user_id' => 'alice']), null))->toThrow(ValidationException::class);
});

test('undeclared operations and duplicate record identities are rejected', function (): void {
    expect(fn () => nestedExecute('create', nestedArgs(1, ['name' => 'Party'], ['person' => nestedRecords('update', nestedArgs(2, ['title' => 'Person']))])))->toThrow(ValidationException::class);
    expect(fn () => nestedExecute('create', nestedArgs(1, ['name' => 'Party'], ['people' => nestedRecords('create', nestedArgs(2, ['title' => 'One']), nestedArgs(2, ['title' => 'Two']))])))->toThrow(ValidationException::class);
    expect(NestedParty::count())->toBe(0)->and(NestedPerson::count())->toBe(0);
});

test('inherited authorization propagates to explicitly declared grandchildren', function (): void {
    NestedParty::$mode = 'parent';
    NestedPersonPolicy::$allow = false;
    nestedExecute('create', nestedArgs(1, ['name' => 'Party'], ['person' => nestedRecords('create', nestedArgs(2, ['title' => 'Person'], ['addresses' => nestedRecords('create', nestedArgs(3, ['body' => 'Address']))]))]));
    expect(NestedAddress::findOrFail(nestedId(3))->task_id)->toBe(nestedId(2));
});

test('inherited authorization also applies to updates and relationship update abilities receive both records', function (): void {
    nestedExecute('create', nestedArgs(1, ['name' => 'Party'], ['person' => nestedRecords('create', nestedArgs(2, ['title' => 'Person']))]));
    NestedPersonPolicy::$allow = false;
    NestedParty::$mode = 'parent';
    nestedExecute('update', nestedArgs(1, [], ['person' => nestedRecords('update', nestedArgs(2, ['title' => 'Inherited']))]));
    NestedPersonPolicy::$allow = true;
    NestedParty::$mode = 'ability';
    NestedPartyPolicy::$allowRelation = false;
    expect(fn () => nestedExecute('update', nestedArgs(1, [], ['person' => nestedRecords('update', nestedArgs(2, ['title' => 'Denied']))])))->toThrow(AuthorizationException::class);
    NestedPartyPolicy::$allowRelation = true;
    nestedExecute('update', nestedArgs(1, [], ['person' => nestedRecords('update', nestedArgs(2, ['title' => 'Allowed']))]));
});

test('model events cannot change relationship keys and linking input errors use form paths', function (): void {
    try {
        nestedExecute('create', nestedArgs(1, ['name' => 'Party'], ['person' => nestedRecords('create', nestedArgs(2, ['title' => 'Person', 'list_id' => nestedId(3)]))]));
        throw new RuntimeException('Expected validation failure.');
    } catch (ValidationException $validationException) {
        expect($validationException->errors())->toHaveKey('person.list_id');
    }

    NestedPerson::creating(fn (NestedPerson $person) => $person->setAttribute('list_id', nestedId(3)));
    try {
        expect(fn () => nestedExecute('create', nestedArgs(1, ['name' => 'Party'], ['person' => nestedRecords('create', nestedArgs(2, ['title' => 'Person']))])))->toThrow(ValidationException::class);
        expect(NestedParty::count())->toBe(0)->and(NestedPerson::count())->toBe(0);
    } finally {
        NestedPerson::flushEventListeners();
    }
});

test('null relation envelopes and oversized graphs fail without partial writes', function (): void {
    $args = nestedArgs(1, ['name' => 'Party']);
    $args['relations'] = null;
    expect(fn () => nestedExecute('create', $args))->toThrow(ValidationException::class);
    $records = [];
    for ($index = 2; $index <= 101; $index++) {
        $records[] = nestedArgs($index, ['title' => 'Person']);
    }

    expect(fn () => nestedExecute('create', nestedArgs(1, ['name' => 'Party'], ['people' => nestedRecords('create', ...$records)])))->toThrow(ValidationException::class);
    expect(NestedParty::count())->toBe(0)->and(NestedPerson::count())->toBe(0);
});

/** @property string $name
 * @property string|null $host_id
 */
#[ZeroMutate('create', 'update')]
class NestedParty extends Model
{
    use HasUuids;

    public static string $mode = 'default';

    public $timestamps = false;

    protected $table = 'lists';

    protected $fillable = ['name', 'host_id'];

    public static function zero(): ZeroDefinition
    {
        $create = NestedMutation::create('person');
        if (self::$mode === 'parent') {
            $create->authorizeUsingParent();
        }

        if (self::$mode === 'ability') {
            $create->authorize('addPerson');
        }

        $update = NestedMutation::update('person');
        if (self::$mode === 'parent') {
            $update->authorizeUsingParent();
        }

        if (self::$mode === 'ability') {
            $update->authorize('updatePerson');
        }

        return ZeroDefinition::make()->publicReads()->fillFromContext('user_id', ContextField::UserId)
            ->allowedNestedMutations('create', $create, NestedMutation::create('people'), NestedMutation::create('host'))
            ->allowedNestedMutations('update', NestedMutation::create('person'), $update, NestedMutation::create('host'), NestedMutation::update('host'));
    }

    /** @return HasOne<NestedPerson, $this> */
    public function person(): HasOne
    {
        return $this->hasOne(NestedPerson::class, 'list_id');
    }

    /** @return HasMany<NestedPerson, $this> */
    public function people(): HasMany
    {
        return $this->hasMany(NestedPerson::class, 'list_id');
    }

    /** @return BelongsTo<NestedPerson, $this> */
    public function host(): BelongsTo
    {
        return $this->belongsTo(NestedPerson::class, 'host_id');
    }
}

/** @property string $title
 * @property string|null $list_id
 */
class NestedPerson extends Model
{
    use HasUuids;

    protected $table = 'tasks';

    protected $fillable = ['title', 'list_id'];

    public static function zero(): ZeroDefinition
    {
        return ZeroDefinition::make()->publicReads()->fillFromContext('user_id', ContextField::UserId)
            ->mutationRequests(['create' => NestedPersonRequest::class, 'update' => NestedPersonRequest::class])
            ->allowedNestedMutations('create', NestedMutation::create('addresses')->authorizeUsingParent());
    }

    /** @return HasMany<NestedAddress, $this> */
    public function addresses(): HasMany
    {
        return $this->hasMany(NestedAddress::class, 'task_id');
    }
}

/** @property string $task_id */
class NestedAddress extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $table = 'comments';

    protected $fillable = ['body'];

    public static function zero(): ZeroDefinition
    {
        return ZeroDefinition::make()->publicReads()->fillFromContext('user_id', ContextField::UserId)->mutationRequests(['create' => NestedAddressRequest::class]);
    }
}

class NestedPartyPolicy
{
    public static bool $allow = true;

    public static bool $allowRelation = true;

    public function create(User $user): bool
    {
        return self::$allow;
    }

    public function update(User $user, NestedParty $party): bool
    {
        return self::$allow;
    }

    public function updatePerson(User $user, NestedParty $party, NestedPerson $person): bool
    {
        return self::$allowRelation && $person->list_id === $party->getKey();
    }

    public function addPerson(User $user, NestedParty $party): bool
    {
        return self::$allowRelation && $party->exists;
    }
}

class NestedPersonPolicy
{
    public static bool $allow = true;

    public function create(User $user): bool
    {
        return self::$allow;
    }

    public function update(User $user, NestedPerson $person): bool
    {
        return self::$allow;
    }
}

class NestedPersonRequest extends FormRequest
{
    public static bool $allow = true;

    public static bool $rewriteKey = false;

    public function authorize(): bool
    {
        return self::$allow && MutationContext::from($this)->parent instanceof NestedParty;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return ['title' => ['required', 'string', 'max:20'], 'list_id' => ['nullable', 'exists:lists,id']];
    }

    protected function passedValidation(): void
    {
        if (self::$rewriteKey) {
            $this->merge(['list_id' => 'forged']);
        }
    }
}

class NestedAddressRequest extends FormRequest
{
    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return ['body' => ['required', 'string'], 'task_id' => ['required', 'exists:tasks,id']];
    }
}
