<?php

declare(strict_types=1);

namespace LaravelZero;

use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use LaravelZero\Console\GenerateCommand;
use LaravelZero\Console\SyncPublicationCommand;
use LaravelZero\Http\AuthenticateZeroCache;
use LaravelZero\Http\ZeroController;
use LaravelZero\Metadata\ModelDiscovery;
use LaravelZero\Metadata\ModelRegistry;
use LaravelZero\Mutation\CrudExecutor;
use LaravelZero\Mutation\MutationProcessor;

final class ZeroServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/zero.php', 'zero');
        $this->app->singleton(function (Application $app): ModelRegistry {
            $models = $app['config']->get('zero.models');
            if ($models === []) {
                $models = (new ModelDiscovery)->discover(app_path('Models'), $app->getNamespace().'Models');
            }

            return new ModelRegistry($models);
        });
        $this->app->singleton(MutationProcessor::class, fn ($app): MutationProcessor => new MutationProcessor(
            $app->make(ModelRegistry::class), $app->make(CrudExecutor::class),
            $app['config']->get('zero.app_id'), $app['config']->get('zero.upstream_schema'),
        ));
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([__DIR__.'/../config/zero.php' => config_path('zero.php')], 'zero-config');
            $this->commands([GenerateCommand::class, SyncPublicationCommand::class]);
        }

        if (! $this->app->routesAreCached()) {
            Route::prefix(config('zero.route_prefix'))->middleware(array_merge([AuthenticateZeroCache::class], config('zero.middleware')))->group(function (): void {
                Route::post('query', [ZeroController::class, 'query'])->name('zero.query');
                Route::post('mutate', [ZeroController::class, 'mutate'])->name('zero.mutate');
            });
        }
    }
}
