<?php

declare(strict_types=1);

namespace ZeroForLaravel\Tests\Feature;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use LogicException;
use ZeroForLaravel\Attributes\ZeroExclude;
use ZeroForLaravel\Attributes\ZeroPublic;
use ZeroForLaravel\Definition\AllowedFilter;
use ZeroForLaravel\Definition\ZeroDefinition;
use ZeroForLaravel\Metadata\ModelRegistry;
use ZeroForLaravel\Tests\Fixtures\Comment;
use ZeroForLaravel\Tests\Fixtures\Tag;
use ZeroForLaravel\Tests\Fixtures\Task;
use ZeroForLaravel\Tests\Fixtures\TaskList;
use ZeroForLaravel\Tests\TestCase;

uses(TestCase::class);

test('model identity must match the database primary key', function (): void {
    new ModelRegistry([WrongKeyTask::class]);
})->throws(LogicException::class, 'primary key');

test('date columns follow zeros numeric wire representation', function (): void {
    /** @var TestCase $this */
    Schema::create('calendars', function (Blueprint $table): void {
        $table->uuid('id')->primary();
        $table->date('day');
    });
    $column = (new ModelRegistry([Calendar::class]))->get('Calendar')->columns['day'];
    $this->assertSame('number', $column['type']);
    $this->assertTrue($column['date']);
});

test('json filters fail during generation', function (): void {
    DB::statement('ALTER TABLE tasks ADD COLUMN payload JSON');
    new ModelRegistry([JsonFilteredTask::class]);
})->throws(LogicException::class, 'JSON');

test('resolves one column plan and separate public mutation inputs', function (): void {
    /** @var TestCase $this */
    $registry = new ModelRegistry($this->models());
    $task = $registry->get('Task');
    $this->assertArrayNotHasKey('secret', $task->columns);
    $this->assertSame('uuid', $task->idGenerator);
    $this->assertTrue($task->columns['completed_at']['date']);
    $this->assertSame('boolean', $task->columns['done']['type']);
    $this->assertSame(false, $task->columns['done']['default']);
    $this->assertSame(['list_id', 'title', 'done', 'completed_at'], $task->writable);
    $this->assertSame(['user_id' => 'user_id'], $task->serverFill);
    $this->assertSame('TaskList', $task->relations['list']['model']);
    $this->assertSame('one', $task->relations['list']['cardinality']);
});

test('reads fail closed without an explicit visibility declaration', function (): void {
    new ModelRegistry([UnprotectedTask::class]);
})->throws(LogicException::class, 'visibility');

test('hidden relation keys are not silently republished', function (): void {
    new ModelRegistry([HiddenRelationTask::class, TaskList::class, Tag::class, Comment::class]);
})->throws(LogicException::class, 'list_id');

test('exclusions are additive to eloquent hidden columns', function (): void {
    /** @var TestCase $this */
    $task = (new ModelRegistry([ExcludedTask::class]))->get('ExcludedTask');
    $this->assertArrayNotHasKey('secret', $task->columns);
    $this->assertArrayNotHasKey('completed_at', $task->columns);
});

#[ZeroPublic]
class Calendar extends Model
{
    public $timestamps = false;

    protected function casts(): array
    {
        return ['day' => 'immutable_date'];
    }
}

class JsonFilteredTask extends Task
{
    public static function zero(): ZeroDefinition
    {
        return ZeroDefinition::make()->allowedFilters([AllowedFilter::exact('payload')]);
    }
}

class WrongKeyTask extends Task
{
    protected $primaryKey = 'title';

    public static function zero(): ZeroDefinition
    {
        return ZeroDefinition::make();
    }
}

class UnprotectedTask extends Model
{
    protected $table = 'tasks';
}

class HiddenRelationTask extends Task
{
    protected $hidden = ['secret', 'list_id'];
}

#[ZeroPublic]
#[ZeroExclude(['completed_at'])]
class ExcludedTask extends Model
{
    protected $table = 'tasks';

    protected $hidden = ['secret'];
}

#[ZeroPublic]
class InvalidDefinitionTask extends Model
{
    protected $table = 'tasks';

    public static function zero(): string
    {
        return 'invalid';
    }
}

class InvalidRelationTask extends Task
{
    public static function zero(): ZeroDefinition
    {
        return ZeroDefinition::make()->allowedIncludes(['invalidRelation']);
    }

    public function invalidRelation(): string
    {
        return 'invalid';
    }
}

test('model definitions must return ZeroDefinition', function (): void {
    new ModelRegistry([InvalidDefinitionTask::class]);
})->throws(LogicException::class, 'zero() must return ZeroDefinition');

test('included methods must return an Eloquent relation', function (): void {
    new ModelRegistry([InvalidRelationTask::class]);
})->throws(LogicException::class, 'method must return an Eloquent relation');

enum CastStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
}

class CastTask extends Task
{
    protected $fillable = ['title', 'options', 'status'];

    public static function zero(): ZeroDefinition
    {
        return ZeroDefinition::make();
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['done' => 'boolean', 'completed_at' => 'immutable_datetime', 'options' => 'array', 'status' => CastStatus::class];
    }
}

test('exports native cast metadata including implicit timestamps and backed enum values', function (): void {
    Schema::table('tasks', function (Blueprint $table): void {
        $table->text('options')->nullable();
        $table->string('status')->default('draft');
    });
    $model = (new ModelRegistry([CastTask::class]))->get('CastTask');
    expect($model->columns['created_at']['date'])->toBeTrue();
    expect($model->columns['updated_at']['date'])->toBeTrue();
    expect($model->columns['deleted_at']['date'])->toBeTrue();
    expect($model->columns['options']['cast'] ?? null)->toBe('array');
    expect($model->columns['status']['enum'])->toBe(['draft', 'published']);
    expect($model->columns['done']['cast'] ?? null)->toBe('boolean');
});
