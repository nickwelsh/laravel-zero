<?php

namespace NickWelsh\LaravelZero\Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use NickWelsh\LaravelZero\Context\ZeroContextResolver;
use NickWelsh\LaravelZero\Contracts\ZeroSchemaRegistry;
use NickWelsh\LaravelZero\LaravelZeroServiceProvider;
use NickWelsh\LaravelZero\Tests\Fixtures\FakeContextResolver;
use NickWelsh\LaravelZero\Tests\Fixtures\FakeSchemaRegistry;
use NickWelsh\LaravelZero\Tests\Fixtures\TestZeroContext;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [LaravelZeroServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $app['config']->set('laravel-zero.context.class', TestZeroContext::class);
        $app['config']->set('laravel-zero.context.resolver', FakeContextResolver::class);
        $app['config']->set('laravel-zero.discovery.directories', [__DIR__.'/Fixtures/Zero']);
        $app['config']->set('laravel-zero.routes.middleware', []);
        $app['config']->set('laravel-zero.generation.output_directory', __DIR__.'/types/generated');
        $app['config']->set('laravel-zero.generation.generate_schema', false);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->singleton(ZeroSchemaRegistry::class, FakeSchemaRegistry::class);
        $this->app->singleton(ZeroContextResolver::class, FakeContextResolver::class);
        $this->migrateFixtureDatabase();
    }

    private function migrateFixtureDatabase(): void
    {
        $this->app['db']->connection()->statement('ATTACH DATABASE \':memory:\' AS "zero_0"');

        Schema::create('parties', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('user_id');
            $table->string('display_name');
            $table->string('reference_code')->nullable();
        });
        Schema::create('email_addresses', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('party_id');
            $table->boolean('is_primary');
        });
        Schema::create('zero_0.clients', function (Blueprint $table): void {
            $table->string('clientGroupID');
            $table->string('clientID');
            $table->unsignedBigInteger('lastMutationID');
            $table->string('userID')->nullable();
            $table->primary(['clientGroupID', 'clientID']);
        });
        Schema::create('zero_0.mutations', function (Blueprint $table): void {
            $table->string('clientGroupID');
            $table->string('clientID');
            $table->unsignedBigInteger('mutationID');
            $table->json('result');
            $table->primary(['clientGroupID', 'clientID', 'mutationID']);
        });
    }
}
