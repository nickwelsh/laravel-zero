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
        $app['config']->set('laravel-zero.generation.output_directory', sys_get_temp_dir().'/laravel-zero-tests/generated-'.spl_object_id($app));
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
        Schema::create('parties', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('user_id');
            $table->string('display_name');
            $table->string('reference_code')->nullable();
        });
        Schema::create('zero_clients', function (Blueprint $table): void {
            $table->id();
            $table->string('upstream_schema');
            $table->string('client_group_id');
            $table->string('client_id');
            $table->unsignedBigInteger('last_mutation_id')->default(0);
            $table->unique(['upstream_schema', 'client_group_id', 'client_id']);
        });
        Schema::create('zero_mutation_results', function (Blueprint $table): void {
            $table->id();
            $table->string('upstream_schema');
            $table->string('client_group_id');
            $table->string('client_id');
            $table->unsignedBigInteger('mutation_id');
            $table->json('result');
            $table->unique(['upstream_schema', 'client_group_id', 'client_id', 'mutation_id']);
        });
    }
}
