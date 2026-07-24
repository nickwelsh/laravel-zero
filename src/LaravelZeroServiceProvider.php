<?php

namespace NickWelsh\LaravelZero;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Foundation\Events\VendorTagPublished;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use NickWelsh\EloquentZero\Support\Casing;
use NickWelsh\EloquentZero\Support\Mode;
use NickWelsh\LaravelZero\Commands\CheckCommand;
use NickWelsh\LaravelZero\Commands\ClearRegistryCommand;
use NickWelsh\LaravelZero\Commands\GenerateCommand;
use NickWelsh\LaravelZero\Context\ZeroContextResolver;
use NickWelsh\LaravelZero\Contracts\ValidationSchema;
use NickWelsh\LaravelZero\Contracts\ZeroSchemaRegistry;
use NickWelsh\LaravelZero\Discovery\ZeroRegistry;
use NickWelsh\LaravelZero\Installation\FrontendScaffolder;
use NickWelsh\LaravelZero\Schema\EloquentZeroSchemaRegistry;
use NickWelsh\LaravelZero\Support\GeneratedPaths;
use NickWelsh\LaravelZero\Validation\Zod;
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
        $this->syncEloquentZeroConfig();

        $this->app->singleton(ZeroSchemaRegistry::class, EloquentZeroSchemaRegistry::class);
        $this->app->singleton(ZeroRegistry::class);
        $this->app->singleton(FrontendScaffolder::class);
        $this->app->singleton(ValidationSchema::class, function ($app): ValidationSchema {
            /** @var Container $app */
            $class = config('laravel-zero.validation.schema', Zod::class);
            if (! is_string($class) || ! is_subclass_of($class, ValidationSchema::class)) {
                throw new \UnexpectedValueException('Configuration [laravel-zero.validation.schema] must be a '.ValidationSchema::class.' class.');
            }

            /** @var ValidationSchema */
            return $app->make($class);
        });
        $this->app->bind(ZeroContextResolver::class, function ($app) {
            /** @var Container $app */
            /** @var class-string<ZeroContextResolver> $resolver */
            $resolver = config('laravel-zero.context.resolver');
            /** @var ZeroContextResolver $contextResolver */
            $contextResolver = $app->make($resolver);

            return $contextResolver;
        });
    }

    public function packageBooted(): void
    {
        $context = [
            __DIR__.'/../stubs/ZeroContext.php.stub' => app_path('Zero/ZeroContext.php'),
            __DIR__.'/../stubs/ContextResolver.php.stub' => app_path('Zero/ContextResolver.php'),
        ];

        $this->publishes($context, 'zero-context');
        $this->publishes([
            __DIR__.'/../config/laravel-zero.php' => config_path('laravel-zero.php'),
            ...$context,
        ], 'zero');

        $this->app->make(Dispatcher::class)->listen(VendorTagPublished::class, function (VendorTagPublished $event): void {
            if ($event->tag === 'zero') {
                $this->app->make(FrontendScaffolder::class)->scaffold();
            }
        });

        if (config('laravel-zero.routes.enabled') !== false) {
            $this->registerCsrfExclusions();
            $this->loadRoutesFrom(__DIR__.'/../routes/zero.php');
        }
    }

    private function registerCsrfExclusions(): void
    {
        if (config('laravel-zero.routes.except_from_csrf', true) !== true) {
            return;
        }

        /** @var scalar|\Stringable|null $configuredPrefix */
        $configuredPrefix = config('laravel-zero.routes.prefix', 'zero');
        $prefix = trim((string) $configuredPrefix, '/');
        $paths = array_map(
            fn (string $endpoint): string => $prefix === '' ? $endpoint : $prefix.'/'.$endpoint,
            ['query', 'mutate'],
        );

        if (class_exists(PreventRequestForgery::class)) {
            PreventRequestForgery::except($paths);

            return;
        }

        $middleware = 'Illuminate\\Foundation\\Http\\Middleware\\ValidateCsrfToken';
        $middleware::except($paths);
    }

    private function syncEloquentZeroConfig(): void
    {
        $config = $this->app->make(Repository::class);
        $config->set([
            'eloquent-zero.mode' => $config->get('laravel-zero.generation.mode', Mode::OptOut),
            'eloquent-zero.model_search_directories' => $config->get('laravel-zero.generation.model_search_directories', [app_path('Models')]),
            'eloquent-zero.models' => $config->get('laravel-zero.generation.models', []),
            'eloquent-zero.tables' => $config->get('laravel-zero.generation.tables', []),
            'eloquent-zero.output_path' => GeneratedPaths::schema(),
            'eloquent-zero.table_name_casing' => $config->get('laravel-zero.generation.table_name_casing', Casing::Camel),
            'eloquent-zero.column_name_casing' => $config->get('laravel-zero.generation.column_name_casing', Casing::Camel),
            'eloquent-zero.use_wayfinder' => $config->get('laravel-zero.generation.use_wayfinder', false),
            'eloquent-zero.connection' => $config->get('laravel-zero.database.connection'),
            'eloquent-zero.allow_multiple_connections' => $config->get('laravel-zero.database.allow_multiple_connections', false),
            'eloquent-zero.publication_name' => $config->get('laravel-zero.database.publication_name'),
        ]);
    }
}
