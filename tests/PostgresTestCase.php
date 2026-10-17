<?php

declare(strict_types=1);

namespace ZeroForLaravel\Tests;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use LogicException;

abstract class PostgresTestCase extends TestCase
{
    private bool $ownsTables = false;

    protected function setUp(): void
    {
        if (! getenv('ZERO_TEST_PG_DATABASE')) {
            $this->markTestSkipped('Set ZERO_TEST_PG_DATABASE to a dedicated disposable PostgreSQL test database.');
        }

        parent::setUp();
        DB::statement('CREATE SCHEMA zero_test_0');
        DB::statement('CREATE TABLE zero_test_0.clients ("clientGroupID" text, "clientID" text, "lastMutationID" bigint, PRIMARY KEY ("clientGroupID", "clientID"))');
        DB::statement('CREATE TABLE zero_test_0.mutations ("clientGroupID" text, "clientID" text, "mutationID" bigint, result json, PRIMARY KEY ("clientGroupID", "clientID", "mutationID"))');
    }

    protected function tearDown(): void
    {
        if ($this->ownsTables && isset($this->app) && $this->app !== null) {
            DB::statement('DROP SCHEMA IF EXISTS zero_test_0 CASCADE');
            Schema::dropIfExists('tasks');
            Schema::dropIfExists('lists');
            Schema::dropIfExists('task_tag');
            Schema::dropIfExists('tags');
            Schema::dropIfExists('comments');
        }

        parent::tearDown();
    }

    protected function createTables(): void
    {
        $name = DB::scalar('SELECT current_database()');
        if (! is_string($name) || ! preg_match('/^laravel_zero_test(?:_[a-z0-9_]+)?$/D', $name)) {
            throw new LogicException('PostgreSQL tests require a disposable database named laravel_zero_test (or laravel_zero_test_*).');
        }

        if (DB::scalar("SELECT to_regnamespace('zero_test_0')") !== null ||
            DB::select("SELECT tablename FROM pg_tables WHERE schemaname = 'public'") !== [] ||
            DB::select("SELECT pubname FROM pg_publication WHERE pubname = 'laravel_zero_test_publication'") !== []) {
            throw new LogicException('Refusing to use a PostgreSQL test database containing existing application tables, test bookkeeping, or a test publication.');
        }

        $this->ownsTables = true;
        parent::createTables();
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'pgsql', 'host' => getenv('ZERO_TEST_PG_HOST') ?: '127.0.0.1',
            'port' => getenv('ZERO_TEST_PG_PORT') ?: '5432',
            'database' => getenv('ZERO_TEST_PG_DATABASE') ?: 'laravel_zero_test',
            'username' => getenv('ZERO_TEST_PG_USER') ?: 'postgres',
            'password' => getenv('ZERO_TEST_PG_PASSWORD') ?: '',
            'charset' => 'utf8', 'prefix' => '', 'search_path' => 'public', 'sslmode' => 'prefer',
        ]);
    }
}
