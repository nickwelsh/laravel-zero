<?php

declare(strict_types=1);

namespace LaravelZero\Tests\Feature;

use Illuminate\Support\Facades\DB;
use LaravelZero\Metadata\ModelRegistry;
use LaravelZero\Publication\PublicationManager;
use LaravelZero\Tests\Fixtures\VisibleTask;
use LaravelZero\Tests\PostgresTestCase;
use LogicException;

uses(PostgresTestCase::class);

test('visible allowlists restrict publication SQL and published columns', function (): void {
    $manager = new PublicationManager(new ModelRegistry([VisibleTask::class]));
    try {
        $plan = implode("\n", $manager->plan('laravel_zero_test_publication'));
        expect($plan)->toContain('public."tasks" ("id", "title")');
        expect($plan)->not->toContain('secret');
        $manager->sync('laravel_zero_test_publication');
        expect(DB::scalar("SELECT attnames::text FROM pg_publication_tables WHERE pubname = 'laravel_zero_test_publication' AND tablename = 'tasks'"))
            ->toBe('{id,title}');
    } finally {
        DB::statement('DROP PUBLICATION IF EXISTS laravel_zero_test_publication');
    }
});

test('does not reuse native zeros reserved publication names', function (): void {
    /** @var PostgresTestCase $this */
    (new PublicationManager(new ModelRegistry($this->models())))->plan('_zero_public_0');
})->throws(LogicException::class);

test('does not reuse a publication of an entire schema', function (): void {
    /** @var PostgresTestCase $this */
    DB::statement('CREATE PUBLICATION laravel_zero_test_publication FOR TABLES IN SCHEMA public');
    try {
        (new PublicationManager(new ModelRegistry($this->models())))->sync('laravel_zero_test_publication');
    } finally {
        DB::statement('DROP PUBLICATION laravel_zero_test_publication');
    }
})->throws(LogicException::class);

test('refuses models resolved through another postgres schema', function (): void {
    /** @var PostgresTestCase $this */
    DB::statement('CREATE SCHEMA tenant');
    DB::statement('SET search_path TO tenant, public');
    try {
        new ModelRegistry($this->models());
    } finally {
        DB::statement('SET search_path TO public');
        DB::statement('DROP SCHEMA tenant');
    }
})->throws(LogicException::class, 'public');

test('publication matches the generated column plan and sync is idempotent', function (): void {
    /** @var PostgresTestCase $this */
    $manager = new PublicationManager(new ModelRegistry($this->models()));
    try {
        $plan = $manager->plan('laravel_zero_test_publication');
        $this->assertStringNotContainsString('secret', implode("\n", $plan));
        $manager->sync('laravel_zero_test_publication');
        $manager->sync('laravel_zero_test_publication');
        $tables = DB::select("SELECT tablename, attnames FROM pg_publication_tables WHERE pubname = 'laravel_zero_test_publication' ORDER BY tablename");
        $this->assertSame(['comments', 'lists', 'tags', 'task_tag', 'tasks'], array_column($tables, 'tablename'));
        $columns = DB::scalar("SELECT attnames::text FROM pg_publication_tables WHERE pubname = 'laravel_zero_test_publication' AND tablename = 'tasks'");
        $this->assertIsString($columns);
        $this->assertStringNotContainsString('secret', $columns);
    } finally {
        DB::statement('DROP PUBLICATION IF EXISTS laravel_zero_test_publication');
    }
});

test('does not reuse a publication that publishes all tables', function (): void {
    /** @var PostgresTestCase $this */
    DB::statement('CREATE PUBLICATION laravel_zero_test_publication FOR ALL TABLES');
    try {
        (new PublicationManager(new ModelRegistry($this->models())))->sync('laravel_zero_test_publication');
    } finally {
        DB::statement('DROP PUBLICATION laravel_zero_test_publication');
    }
})->throws(LogicException::class);
