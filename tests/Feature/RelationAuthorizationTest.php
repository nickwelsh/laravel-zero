<?php

declare(strict_types=1);

namespace LaravelZero\Tests\Feature;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Validation\ValidationException;
use LaravelZero\Attributes\ZeroAuthorizeRelation;
use LaravelZero\Attributes\ZeroQuery;
use LaravelZero\Metadata\ModelRegistry;
use LaravelZero\Query\QueryCompiler;
use LaravelZero\Tests\Fixtures\Tag;
use LaravelZero\Tests\TestCase;
use LaravelZero\ZeroContext;
use LogicException;

uses(TestCase::class);

#[ZeroQuery(['all', 'one'])]
#[ZeroAuthorizeRelation('access')]
class SharedTask extends Model
{
    protected $table = 'tasks';

    protected $hidden = ['secret'];

    /** @return BelongsTo<Tag, $this> */
    public function access(): BelongsTo
    {
        return $this->belongsTo(Tag::class, 'list_id');
    }
}

test('relation authorization requires a related row visible to the trusted user', function (): void {
    /** @var TestCase $this */
    $registry = new ModelRegistry([SharedTask::class, Tag::class]);
    $ast = (new QueryCompiler($registry))->compile('SharedTask.all', [], new ZeroContext(['user_id' => 'alice']));
    $this->assertSame('correlatedSubquery', data_get($ast, 'where.type'));
    $this->assertSame('EXISTS', data_get($ast, 'where.op'));
    $this->assertSame('tags', data_get($ast, 'where.related.subquery.table'));
    $this->assertSame('alice', data_get($ast, 'where.related.subquery.where.right.value'));
    $this->assertSame(['list_id'], data_get($ast, 'where.related.correlation.parentField'));
    $this->assertSame(['access'], $registry->get('SharedTask')->toArray()['authorizationRelations']);
});

test('authorization relations are not automatically allowed includes', function (): void {
    $compiler = new QueryCompiler(new ModelRegistry([SharedTask::class, Tag::class]));
    $compiler->compile('SharedTask.all', ['include' => ['access' => []]], new ZeroContext(['user_id' => 'alice']));
})->throws(ValidationException::class);

test('relation authorization fails closed when trusted context is missing', function (): void {
    $compiler = new QueryCompiler(new ModelRegistry([SharedTask::class, Tag::class]));
    $compiler->compile('SharedTask.all', [], new ZeroContext([]));
})->throws(AuthorizationException::class);

#[ZeroAuthorizeRelation('parent')]
class CyclicTask extends Model
{
    protected $table = 'tasks';

    /** @return BelongsTo<CyclicTask, $this> */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'list_id');
    }
}

test('cyclic authorization relations fail during generation', function (): void {
    new ModelRegistry([CyclicTask::class]);
})->throws(LogicException::class, 'Cyclic authorization');
