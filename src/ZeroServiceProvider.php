<?php

declare(strict_types=1);

namespace LaravelZero;

use Illuminate\Database\Events\MigrationsEnded;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Event;
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
use LaravelZero\Publication\PublicationManager;
use LogicException;

final class ZeroServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/zero.php', 'zero');
        $this->app->singleton(function (Application $app): ModelRegistry {
            $models = config()->array('zero.models');
            if (! array_is_list($models)) {
                throw new LogicException('Zero models must be a list of Eloquent model classes.');
            }

            $classes = [];
            foreach ($models as $model) {
                if (! is_string($model) || ! is_subclass_of($model, \Illuminate\Database\Eloquent\Model::class)) {
                    throw new LogicException('Zero models must be a list of Eloquent model classes.');
                }

                $classes[] = $model;
            }

            if ($models === []) {
                $classes = (new ModelDiscovery)->discover(app_path('Models'), $app->getNamespace().'Models');
            }

            return new ModelRegistry($classes);
        });
        $this->app->singleton(MutationProcessor::class, fn (Application $app): MutationProcessor => new MutationProcessor(
            $app->make(ModelRegistry::class), $app->make(CrudExecutor::class),
            config()->string('zero.app_id'), config()->string('zero.upstream_schema'),
        ));
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([__DIR__.'/../config/zero.php' => config_path('zero.php')], 'zero-config');
            $this->commands([GenerateCommand::class, SyncPublicationCommand::class]);
            Event::listen(MigrationsEnded::class, function (MigrationsEnded $event): void {
                if ($event->method !== 'up' || ($event->options['pretend'] ?? false) || ! config()->boolean('zero.sync_publication_on_migrate')) {
                    return;
                }

                $migrator = $this->app->make(Migrator::class);
                $connection = $migrator->resolveConnection($migrator->getConnection());
                if ($connection->getDriverName() !== 'pgsql') {
                    return;
                }

                // Migrations may have changed columns on an already-resolved registry.
                $this->app->forgetInstance(ModelRegistry::class);
                $registry = $this->app->make(ModelRegistry::class);
                if ($registry->all() === [] || $registry->connection()->getName() !== $connection->getName()) {
                    return;
                }

                (new PublicationManager($registry))->sync(config()->string('zero.publication'));
            });
        }

        if (! $this->app->routesAreCached()) {
            $middleware = config()->array('zero.middleware');
            $names = [];
            foreach ($middleware as $name) {
                if (! is_string($name)) {
                    throw new LogicException('Zero middleware must contain middleware names.');
                }

                $names[] = $name;
            }

            Route::prefix(config()->string('zero.route_prefix'))->middleware(array_merge([AuthenticateZeroCache::class], $names))->group(function (): void {
                Route::post('query', [ZeroController::class, 'query'])->name('zero.query');
                Route::post('mutate', [ZeroController::class, 'mutate'])->name('zero.mutate');
            });
        }
    }
}
