<?php

declare(strict_types=1);

namespace ZeroForLaravel\Tests\Feature;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Events\MigrationsEnded;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use ZeroForLaravel\Attributes\ZeroPublic;
use ZeroForLaravel\Metadata\ModelRegistry;
use ZeroForLaravel\Publication\PublicationManager;
use ZeroForLaravel\Tests\PostgresTestCase;
use ZeroForLaravel\ZeroServiceProvider;

#[ZeroPublic]
class MigrationRecord extends Model
{
    protected $table = 'tasks';

    protected $connection = 'testing';

    protected $hidden = ['secret'];
}

abstract class MigrationPublicationTestCase extends PostgresTestCase
{
    private bool $ownsMigrationArtifacts = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ownsMigrationArtifacts = true;
    }

    protected function tearDown(): void
    {
        if ($this->ownsMigrationArtifacts && isset($this->app) && $this->app !== null) {
            DB::statement('DROP PUBLICATION IF EXISTS laravel_zero_test_publication');
            Schema::dropIfExists('migrations');
        }

        parent::tearDown();
    }

    protected function getPackageProviders($app): array
    {
        return [ZeroServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('zero.models', [MigrationRecord::class]);
        $app['config']->set('zero.publication', 'laravel_zero_test_publication');
        $app['config']->set('zero.sync_publication_on_migrate', true);
    }
}

uses(MigrationPublicationTestCase::class);

test('fresh migrations reattach recreated tables using fresh private column metadata', function (): void {
    /** @var MigrationPublicationTestCase $this */
    $this->assertSame(0, Artisan::call('migrate:install'));
    $registry = resolve(ModelRegistry::class);
    resolve(PublicationManager::class)->sync('laravel_zero_test_publication');

    $this->assertSame(0, Artisan::call('migrate:fresh', [
        '--path' => __DIR__.'/../Fixtures/migrations', '--realpath' => true, '--force' => true,
    ]));

    $this->assertNotSame($registry, resolve(ModelRegistry::class));
    $this->assertSame(['tasks'], array_column(DB::select("SELECT tablename FROM pg_publication_tables WHERE pubname = 'laravel_zero_test_publication'"), 'tablename'));
    $columns = DB::scalar("SELECT attnames::text FROM pg_publication_tables WHERE pubname = 'laravel_zero_test_publication'");
    $this->assertIsString($columns);
    $this->assertStringContainsString('body', $columns);
    $this->assertStringNotContainsString('secret', $columns);
});

test('dry runs rollbacks and disabled synchronization do not alter publications', function (string $method, bool $pretend, bool $enabled): void {
    /** @var MigrationPublicationTestCase $this */
    config()->set('zero.sync_publication_on_migrate', $enabled);
    Event::dispatch(new MigrationsEnded($method, ['pretend' => $pretend]));
    $this->assertNull(DB::scalar("SELECT pubname FROM pg_publication WHERE pubname = 'laravel_zero_test_publication'"));
})->with([
    'dry run' => ['up', true, true],
    'rollback' => ['down', false, true],
    'disabled' => ['up', false, false],
]);

test('migrations on another connection do not synchronize the upstream publication', function (): void {
    /** @var MigrationPublicationTestCase $this */
    config()->set('database.connections.other', config('database.connections.testing'));
    resolve(Migrator::class)->usingConnection('other', fn () => Event::dispatch(new MigrationsEnded('up')));
    $this->assertNull(DB::scalar("SELECT pubname FROM pg_publication WHERE pubname = 'laravel_zero_test_publication'"));
});
