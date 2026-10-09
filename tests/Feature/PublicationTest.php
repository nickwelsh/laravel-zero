<?php

namespace LaravelZero\Tests\Feature;

use Illuminate\Support\Facades\DB;
use LaravelZero\Metadata\ModelRegistry;
use LaravelZero\Publication\PublicationManager;
use LaravelZero\Tests\PostgresTestCase;

class PublicationTest extends PostgresTestCase
{
    public function test_does_not_reuse_native_zeros_reserved_publication_names(): void
    {
        $this->expectException(\LogicException::class);
        (new PublicationManager(new ModelRegistry($this->models())))->plan('_zero_public_0');
    }

    public function test_does_not_reuse_a_publication_of_an_entire_schema(): void
    {
        DB::statement('CREATE PUBLICATION laravel_zero_test_publication FOR TABLES IN SCHEMA public');
        try {
            $this->expectException(\LogicException::class);
            (new PublicationManager(new ModelRegistry($this->models())))->sync('laravel_zero_test_publication');
        } finally {
            DB::statement('DROP PUBLICATION laravel_zero_test_publication');
        }
    }

    public function test_refuses_models_resolved_through_another_postgres_schema(): void
    {
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
    }

    public function test_publication_matches_the_generated_column_plan_and_sync_is_idempotent(): void
    {
        $manager = new PublicationManager(new ModelRegistry($this->models()));
        try {
            $plan = $manager->plan('laravel_zero_test_publication');
            self::assertStringNotContainsString('secret', implode("\n", $plan));
            $manager->sync('laravel_zero_test_publication');
            $manager->sync('laravel_zero_test_publication');
            $tables = DB::select("SELECT tablename, attnames FROM pg_publication_tables WHERE pubname = 'laravel_zero_test_publication' ORDER BY tablename");
            self::assertSame(['comments', 'lists', 'tags', 'task_tag', 'tasks'], array_column($tables, 'tablename'));
            self::assertStringNotContainsString('secret', $tables[4]->attnames);
        } finally {
            DB::statement('DROP PUBLICATION IF EXISTS laravel_zero_test_publication');
        }
    }

    public function test_does_not_reuse_a_publication_that_publishes_all_tables(): void
    {
        DB::statement('CREATE PUBLICATION laravel_zero_test_publication FOR ALL TABLES');
        try {
            $this->expectException(\LogicException::class);
            (new PublicationManager(new ModelRegistry($this->models())))->sync('laravel_zero_test_publication');
        } finally {
            DB::statement('DROP PUBLICATION laravel_zero_test_publication');
        }
    }
}
