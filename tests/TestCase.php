<?php

namespace LaravelZero\Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use LaravelZero\Tests\Fixtures\Comment;
use LaravelZero\Tests\Fixtures\Tag;
use LaravelZero\Tests\Fixtures\Task;
use LaravelZero\Tests\Fixtures\TaskList;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->createTables();
    }

    protected function createTables(): void
    {
        Schema::create('tasks', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('user_id');
            $table->uuid('list_id')->nullable();
            $table->string('title');
            $table->boolean('done')->default(false);
            $table->timestamp('completed_at')->nullable();
            $table->string('secret')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('lists', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('user_id');
            $table->string('name');
        });
        Schema::create('tags', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('user_id');
            $table->string('name');
        });
        Schema::create('task_tag', function (Blueprint $table) {
            $table->uuid('task_id');
            $table->uuid('tag_id');
            $table->primary(['task_id', 'tag_id']);
        });
        Schema::create('comments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('task_id');
            $table->string('user_id');
            $table->string('body');
        });
    }

    protected function models(array $classes = []): array
    {
        return $classes ?: [Task::class, TaskList::class, Tag::class, Comment::class];
    }
}
