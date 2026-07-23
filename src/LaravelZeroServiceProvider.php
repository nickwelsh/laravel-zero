<?php

namespace NickWelsh\LaravelZero;

use NickWelsh\LaravelZero\Commands\CheckCommand;
use NickWelsh\LaravelZero\Commands\ClearRegistryCommand;
use NickWelsh\LaravelZero\Commands\GenerateCommand;
use NickWelsh\LaravelZero\Context\ZeroContextResolver;
use NickWelsh\LaravelZero\Contracts\ZeroSchemaRegistry;
use NickWelsh\LaravelZero\Discovery\ZeroRegistry;
use NickWelsh\LaravelZero\Schema\EloquentZeroSchemaRegistry;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

final class LaravelZeroServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package->name('laravel-zero')->hasConfigFile('laravel-zero')->hasCommands([
            GenerateCommand::class,
            CheckCommand::class,
            ClearRegistryCommand::class,
        ]);
    }

    public function packageRegistered(): void
    {
        $this->app->singleton(ZeroSchemaRegistry::class, EloquentZeroSchemaRegistry::class);
        $this->app->singleton(ZeroRegistry::class);
        $this->app->bind(ZeroContextResolver::class, fn ($app) => $app->make(config('laravel-zero.context.resolver')));
    }

    public function packageBooted(): void
    {
        if (config('laravel-zero.routes.enabled') === false) {
            return;
        }

        $this->loadRoutesFrom(__DIR__.'/../routes/zero.php');
    }
}
