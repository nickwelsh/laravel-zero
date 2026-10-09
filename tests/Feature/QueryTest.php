<?php

declare(strict_types=1);

namespace LaravelZero\Tests\Feature;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use LaravelZero\Definition\AllowedFilter;
use LaravelZero\Definition\ZeroDefinition;
use LaravelZero\Metadata\ModelRegistry;
use LaravelZero\Query\QueryCompiler;
use LaravelZero\Query\QueryProcessor;
use LaravelZero\Tests\Fixtures\Task;
use LaravelZero\Tests\TestCase;
use LaravelZero\ZeroContext;

uses(TestCase::class);

test('nullable substring filters reject null', function (): void {
    $compiler = new QueryCompiler(new ModelRegistry([NullablePartialTask::class]));
    $compiler->compile('NullablePartialTask.all', ['filters' => ['list_id' => null]], new ZeroContext(['user_id' => 'alice']));
})->throws(ValidationException::class);

function queryCompiler(TestCase $test): QueryCompiler
{
    return new QueryCompiler(new ModelRegistry($test->models()));
}

test('root and related authorization cannot be overridden by filters', function (): void {
    /** @var TestCase $this */
    $ast = queryCompiler($this)->compile('Task.all', ['include' => ['list' => []], 'filters' => ['done' => false]], new ZeroContext(['user_id' => 'alice']));
    $this->assertSame('tasks', $ast['table']);
    $this->assertSame(50, $ast['limit']);
    $this->assertSame([['created_at', 'desc'], ['id', 'asc']], $ast['orderBy']);
    $this->assertSame('alice', $ast['where']['conditions'][0]['right']['value']);
    $this->assertSame(false, $ast['where']['conditions'][2]['right']['value']);
    $this->assertSame('alice', $ast['related'][0]['subquery']['where']['right']['value']);
});

test('one supports includes and null filter is distinct from unset', function (): void {
    /** @var TestCase $this */
    $ast = queryCompiler($this)->compile('Task.one', ['id' => 'a', 'include' => ['list' => []]], new ZeroContext(['user_id' => 'alice']));
    $this->assertSame(1, $ast['limit']);
    $ast = queryCompiler($this)->compile('Task.all', ['filters' => ['completed_at' => null]], new ZeroContext(['user_id' => 'alice']));
    $this->assertSame('IS', $ast['where']['conditions'][2]['op']);
});

test('relationship filters use target visibility', function (): void {
    /** @var TestCase $this */
    $ast = queryCompiler($this)->compile('Task.all', ['filters' => ['list' => 'list-a']], new ZeroContext(['user_id' => 'alice']));
    $predicate = $ast['where']['conditions'][2];
    $this->assertSame('correlatedSubquery', $predicate['type']);
    $this->assertSame('alice', $predicate['related']['subquery']['where']['conditions'][0]['right']['value']);
});

test('partial filter escapes sql wildcards', function (): void {
    /** @var TestCase $this */
    $ast = queryCompiler($this)->compile('Task.all', ['filters' => ['title' => 'a%_\\b']], new ZeroContext(['user_id' => 'alice']));
    $this->assertSame('%a\\%\\_\\\\b%', $ast['where']['conditions'][2]['right']['value']);
});

/** @param array<string, mixed> $arguments */
test('rejects invalid or unapproved arguments', function (array $arguments): void {
    /** @var TestCase $this */
    queryCompiler($this)->compile('Task.all', $arguments, new ZeroContext(['user_id' => 'alice']));
})->throws(ValidationException::class)->with('invalid query arguments');

dataset('invalid query arguments', [
    [['filters' => ['secret' => 'x']]], [['filters' => ['done' => 'false']]],
    [['user_id' => 'bob']], [['limit' => 1001]], [['limit' => 1.5]],
    [['order' => [['secret', 'asc']]]], [['include' => ['user' => []]]],
    [['include' => ['list' => ['limit' => 2]]]], [['filters' => ['trashed' => 'unknown']]],
]);

test('missing context fails closed', function (): void {
    /** @var TestCase $this */
    queryCompiler($this)->compile('Task.all', [], new ZeroContext([]));
})->throws(AuthorizationException::class);

test('protocol keeps per query errors separate from authentication', function (): void {
    /** @var TestCase $this */
    $result = (new QueryProcessor(queryCompiler($this)))->process(['transform', [
        ['id' => 'q1', 'name' => 'Task.all', 'args' => [(object) []]],
        ['id' => 'q2', 'name' => 'Task.all', 'args' => [['ast' => ['table' => 'users']]]],
    ]], new ZeroContext(['user_id' => 'alice']));
    $this->assertSame('QueryResponse', $result['kind']);
    $this->assertArrayHasKey('ast', $result['queries'][0]);
    $this->assertSame('parse', $result['queries'][1]['error']);
    $this->assertSame('alice', $result['userID']);
});

class NullablePartialTask extends Task
{
    public static function zero(): ZeroDefinition
    {
        return ZeroDefinition::make()->allowedFilters([AllowedFilter::partial('list_id')]);
    }
}
