<?php

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
use PHPUnit\Framework\Attributes\DataProvider;

class QueryTest extends TestCase
{
    public function test_nullable_substring_filters_reject_null(): void
    {
        $compiler = new QueryCompiler(new ModelRegistry([NullablePartialTask::class]));
        $this->expectException(ValidationException::class);
        $compiler->compile('NullablePartialTask.all', ['filters' => ['list_id' => null]], new ZeroContext(['user_id' => 'alice']));
    }

    private function compiler(): QueryCompiler
    {
        return new QueryCompiler(new ModelRegistry($this->models()));
    }

    public function test_root_and_related_authorization_cannot_be_overridden_by_filters(): void
    {
        $ast = $this->compiler()->compile('Task.all', ['include' => ['list' => []], 'filters' => ['done' => false]], new ZeroContext(['user_id' => 'alice']));
        self::assertSame('tasks', $ast['table']);
        self::assertSame(50, $ast['limit']);
        self::assertSame([['created_at', 'desc'], ['id', 'asc']], $ast['orderBy']);
        self::assertSame('alice', $ast['where']['conditions'][0]['right']['value']);
        self::assertSame(false, $ast['where']['conditions'][2]['right']['value']);
        self::assertSame('alice', $ast['related'][0]['subquery']['where']['right']['value']);
    }

    public function test_one_supports_includes_and_null_filter_is_distinct_from_unset(): void
    {
        $ast = $this->compiler()->compile('Task.one', ['id' => 'a', 'include' => ['list' => []]], new ZeroContext(['user_id' => 'alice']));
        self::assertSame(1, $ast['limit']);
        $ast = $this->compiler()->compile('Task.all', ['filters' => ['completed_at' => null]], new ZeroContext(['user_id' => 'alice']));
        self::assertSame('IS', $ast['where']['conditions'][2]['op']);
    }

    public function test_relationship_filters_use_target_visibility(): void
    {
        $ast = $this->compiler()->compile('Task.all', ['filters' => ['list' => 'list-a']], new ZeroContext(['user_id' => 'alice']));
        $predicate = $ast['where']['conditions'][2];
        self::assertSame('correlatedSubquery', $predicate['type']);
        self::assertSame('alice', $predicate['related']['subquery']['where']['conditions'][0]['right']['value']);
    }

    public function test_partial_filter_escapes_sql_wildcards(): void
    {
        $ast = $this->compiler()->compile('Task.all', ['filters' => ['title' => 'a%_\\b']], new ZeroContext(['user_id' => 'alice']));
        self::assertSame('%a\\%\\_\\\\b%', $ast['where']['conditions'][2]['right']['value']);
    }

    #[DataProvider('invalidArguments')]
    public function test_rejects_invalid_or_unapproved_arguments(array $arguments): void
    {
        $this->expectException(ValidationException::class);
        $this->compiler()->compile('Task.all', $arguments, new ZeroContext(['user_id' => 'alice']));
    }

    public static function invalidArguments(): array
    {
        return [
            [['filters' => ['secret' => 'x']]], [['filters' => ['done' => 'false']]],
            [['user_id' => 'bob']], [['limit' => 1001]], [['limit' => 1.5]],
            [['order' => [['secret', 'asc']]]], [['include' => ['user' => []]]],
            [['include' => ['list' => ['limit' => 2]]]], [['filters' => ['trashed' => 'unknown']]],
        ];
    }

    public function test_missing_context_fails_closed(): void
    {
        $this->expectException(AuthorizationException::class);
        $this->compiler()->compile('Task.all', [], new ZeroContext([]));
    }

    public function test_protocol_keeps_per_query_errors_separate_from_authentication(): void
    {
        $result = (new QueryProcessor($this->compiler()))->process(['transform', [
            ['id' => 'q1', 'name' => 'Task.all', 'args' => [(object) []]],
            ['id' => 'q2', 'name' => 'Task.all', 'args' => [['ast' => ['table' => 'users']]]],
        ]], new ZeroContext(['user_id' => 'alice']));
        self::assertSame('QueryResponse', $result['kind']);
        self::assertArrayHasKey('ast', $result['queries'][0]);
        self::assertSame('parse', $result['queries'][1]['error']);
        self::assertSame('alice', $result['userID']);
    }
}

class NullablePartialTask extends Task
{
    public static function zero(): ZeroDefinition
    {
        return ZeroDefinition::make()->allowedFilters([AllowedFilter::partial('list_id')]);
    }
}
