<?php

declare(strict_types=1);

namespace ZeroForLaravel\Tests\Feature;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Validation\ValidationException;
use LogicException;
use ZeroForLaravel\Attributes\ZeroQuery;
use ZeroForLaravel\ContextField;
use ZeroForLaravel\Definition\AllowedFilter;
use ZeroForLaravel\Definition\ZeroDefinition;
use ZeroForLaravel\Generation\TypeScriptGenerator;
use ZeroForLaravel\Metadata\ModelRegistry;
use ZeroForLaravel\Query\QueryCompiler;
use ZeroForLaravel\Query\ZeroOperator;
use ZeroForLaravel\Query\ZQLBuilder;
use ZeroForLaravel\Tests\TestCase;
use ZeroForLaravel\ZeroContext;

uses(TestCase::class);

#[ZeroQuery(['all', 'one'])]
class DefinedTask extends Model
{
    protected $table = 'tasks';

    protected $fillable = ['user_id', 'title'];

    protected $hidden = ['secret'];

    public static function zero(): ZeroDefinition
    {
        return ZeroDefinition::make()
            ->authorize('user_id', '=', ContextField::UserId)
            ->fillFromContext('user_id', 'user_id')
            ->allowedFilters('title', AllowedFilter::exact('id'), AllowedFilter::belongsTo('list'))
            ->allowedSorts('title', 'created_at')
            ->allowedIncludes('list');
    }

    /** @return BelongsTo<ScopedList, $this> */
    public function list(): BelongsTo
    {
        return $this->belongsTo(ScopedList::class, 'list_id');
    }
}

class ScopedList extends Model
{
    public $timestamps = false;

    public static int $calls = 0;

    protected $table = 'lists';

    public static function zero(): ZeroDefinition
    {
        return ZeroDefinition::make()->enforceServerScope(function (ZQLBuilder $query, ZeroContext $context): void {
            self::$calls++;
            $query->where(fn (ZeroOperator $op): array => $op->or(
                $op->cmp('user_id', $context->get('user_id')),
                $op->cmp('name', 'public'),
            ));
        });
    }
}

#[ZeroQuery(['all'])]
class ScopeRelationTask extends Model
{
    protected $table = 'tasks';

    public static function zero(): ZeroDefinition
    {
        return ZeroDefinition::make()->enforceServerScope(
            fn (ZQLBuilder $query): ZQLBuilder => $query->whereExists('list', fn (ZQLBuilder $list): ZQLBuilder => $list->where('name', 'shared')),
            relations: ['list'],
        );
    }

    /** @return BelongsTo<ScopedList, $this> */
    public function list(): BelongsTo
    {
        return $this->belongsTo(ScopedList::class, 'list_id');
    }
}

test('definitions own visibility fills and variadic query declarations', function (): void {
    $registry = new ModelRegistry([DefinedTask::class, ScopedList::class]);
    $metadata = $registry->get('DefinedTask');
    expect($metadata->writable)->toBe(['title']);
    expect($metadata->serverFill)->toBe(['user_id' => 'user_id']);
    expect(array_keys($metadata->definition->filters))->toBe(['title', 'id', 'list']);
    expect($metadata->definition->sorts)->toBe(['title', 'created_at']);

    $ast = (new QueryCompiler($registry))->compile('DefinedTask.all', [], new ZeroContext(['user_id' => 'alice']));
    expect(data_get($ast, 'where.right.value'))->toBe('alice');
});

test('server scopes run per request including nested rows but never during generation', function (): void {
    ScopedList::$calls = 0;
    $registry = new ModelRegistry([DefinedTask::class, ScopedList::class]);
    $source = (new TypeScriptGenerator)->generate($registry);
    expect(ScopedList::$calls)->toBe(0);
    expect($source)->not->toContain('publicReads', 'serverScopes');

    $compiler = new QueryCompiler($registry);
    foreach (['alice', 'bob'] as $user) {
        $ast = $compiler->compile('DefinedTask.all', ['include' => ['list' => []]], new ZeroContext(['user_id' => $user]));
        expect(data_get($ast, 'related.0.subquery.where.type'))->toBe('or');
        expect(data_get($ast, 'related.0.subquery.where.conditions.0.right.value'))->toBe($user);
    }

    expect(ScopedList::$calls)->toBe(2);
});

test('scope relations keep target authorization and do not become public includes', function (): void {
    $registry = new ModelRegistry([ScopeRelationTask::class, ScopedList::class]);
    $compiler = new QueryCompiler($registry);
    $ast = $compiler->compile('ScopeRelationTask.all', [], new ZeroContext(['user_id' => 'alice']));
    expect(data_get($ast, 'where.op'))->toBe('EXISTS');
    expect(data_get($ast, 'where.related.subquery.where.conditions.0.type'))->toBe('or');
    expect(data_get($ast, 'where.related.subquery.where.conditions.1.right.value'))->toBe('shared');
    expect(fn (): array => $compiler->compile('ScopeRelationTask.all', ['include' => ['list' => []]], new ZeroContext(['user_id' => 'alice'])))->toThrow(ValidationException::class);
});

test('an empty mandatory scope cannot silently grant access', function (): void {
    $registry = new ModelRegistry([DefinedTask::class, ScopedList::class]);
    $registry->get('ScopedList')->definition->serverScopes = [fn (): null => null];
    (new QueryCompiler($registry))->compile('DefinedTask.all', ['include' => ['list' => []]], new ZeroContext(['user_id' => 'alice']));
})->throws(LogicException::class, 'must add');

test('missing scope context is denied on included models', function (): void {
    $registry = new ModelRegistry([ScopeRelationTask::class, ScopedList::class]);
    (new QueryCompiler($registry))->compile('ScopeRelationTask.all', [], new ZeroContext([]));
})->throws(AuthorizationException::class);

test('relationship filters retain mandatory server scopes on their targets', function (): void {
    $registry = new ModelRegistry([DefinedTask::class, ScopedList::class]);
    $ast = (new QueryCompiler($registry))->compile('DefinedTask.all', ['filters' => ['list' => 'list-a']], new ZeroContext(['user_id' => 'alice']));
    expect(data_get($ast, 'where.conditions.1.related.subquery.where.conditions.0.type'))->toBe('or');
    expect(data_get($ast, 'where.conditions.1.related.subquery.where.conditions.1.right.value'))->toBe('list-a');
});
