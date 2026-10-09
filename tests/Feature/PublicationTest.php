<?php

namespace LaravelZero\Tests\Feature;

use Illuminate\Support\Facades\DB;
use LaravelZero\Metadata\ModelRegistry;
use LaravelZero\Publication\PublicationManager;
use LaravelZero\Tests\PostgresTestCase;

uses(PostgresTestCase::class);

test('does not reuse native zeros reserved publication names', function (): void {
        $this->expectException(\LogicException::class);
        (new PublicationManager(new ModelRegistry($this->models())))->plan('_zero_public_0');
    });

test('does not reuse a publication of an entire schema', function (): void {
        DB::statement('CREATE PUBLICATION laravel_zero_test_publication FOR TABLES IN SCHEMA public');
        try {
            $this->expectException(\LogicException::class);
            (new PublicationManager(new ModelRegistry($this->models())))->sync('laravel_zero_test_publication');
        } finally {
            DB::statement('DROP PUBLICATION laravel_zero_test_publication');
        }
    });

test('refuses models resolved through another postgres schema', function (): void {
        DB::statement('CREATE SCHEMA tenant');
        DB::statement('SET search_path TO tenant, public');
        try {
            $this->expectException(\LogicException::class);
            $this->expectExceptionMessage('public');
            new ModelRegistry($this->models());
        } finally {
            DB::statement('SET search_path TO public');
            DB::statement('DROP SCHEMA tenant');
        }
    });

test('publication matches the generated column plan and sync is idempotent', function (): void {
        $manager = new PublicationManager(new ModelRegistry($this->models()));
        try {
            $plan = $manager->plan('laravel_zero_test_publication');
            $this->assertStringNotContainsString('secret', implode("\n", $plan));
            $manager->sync('laravel_zero_test_publication');
            $manager->sync('laravel_zero_test_publication');
            $tables = DB::select("SELECT tablename, attnames FROM pg_publication_tables WHERE pubname = 'laravel_zero_test_publication' ORDER BY tablename");
            $this->assertSame(['comments', 'lists', 'tags', 'task_tag', 'tasks'], array_column($tables, 'tablename'));
            $this->assertStringNotContainsString('secret', $tables[4]->attnames);
        } finally {
            DB::statement('DROP PUBLICATION IF EXISTS laravel_zero_test_publication');
        }
    });

test('does not reuse a publication that publishes all tables', function (): void {
        DB::statement('CREATE PUBLICATION laravel_zero_test_publication FOR ALL TABLES');
        try {
            $this->expectException(\LogicException::class);
            (new PublicationManager(new ModelRegistry($this->models())))->sync('laravel_zero_test_publication');
        } finally {
            DB::statement('DROP PUBLICATION laravel_zero_test_publication');
        }
    });

