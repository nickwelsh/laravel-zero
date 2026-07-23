<?php

use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\ServiceProvider;
use NickWelsh\LaravelZero\Installation\FrontendScaffolder;
use NickWelsh\LaravelZero\LaravelZeroServiceProvider;

it('generates deterministically and checks freshness', function (): void {
    $this->artisan('zero:generate')->assertExitCode(0);
    $first = file_get_contents(config('laravel-zero.generation.output_directory').'/queries.generated.ts');
    $this->artisan('zero:generate')->expectsOutputToContain('unchanged')->assertExitCode(0);
    expect(file_get_contents(config('laravel-zero.generation.output_directory').'/queries.generated.ts'))->toBe($first);
    expect(file_get_contents(config('laravel-zero.generation.output_directory').'/manifest.generated.json'))->toContain('unique:parties,reference_code', '1.8.0');
    $this->artisan('zero:check')->assertExitCode(0);
});

it('scaffolds the configured frontend during generation', function (): void {
    $files = new Filesystem;
    $directory = sys_get_temp_dir().'/laravel-zero-'.uniqid();
    $provider = $directory.'/zero/provider.tsx';
    $globals = $directory.'/globals.ts';

    try {
        config()->set('laravel-zero.frontend', [
            'framework' => 'react',
            'provider_path' => $provider,
            'use_globals' => true,
            'globals_path' => $globals,
        ]);

        $this->artisan('zero:generate')->assertExitCode(0);

        expect($files->get($provider))->toContain("from '@/globals'", 'useMemo<ZeroContext>')
            ->and($files->get($globals))->toContain('VITE_ZERO_CACHE_URL', 'VITE_ZERO_MUTATE_URL', 'VITE_ZERO_QUERY_URL');
    } finally {
        $files->deleteDirectory($directory);
    }
});

it('automatically excludes the Zero endpoints from CSRF validation', function (): void {
    $middleware = class_exists(PreventRequestForgery::class)
        ? PreventRequestForgery::class
        : 'Illuminate\\Foundation\\Http\\Middleware\\ValidateCsrfToken';
    $property = (new ReflectionClass($middleware))->getProperty('neverVerify');

    expect($property->getValue())->toContain('zero/query', 'zero/mutate');
});

it('publishes tagged config, context, and aggregate setup files', function (): void {
    $config = ServiceProvider::pathsToPublish(LaravelZeroServiceProvider::class, 'zero-config');
    $context = ServiceProvider::pathsToPublish(LaravelZeroServiceProvider::class, 'zero-context');
    $all = ServiceProvider::pathsToPublish(LaravelZeroServiceProvider::class, 'zero');

    expect($config)->toHaveCount(1)
        ->and(array_key_first($config))->toEndWith('/config/laravel-zero.php')
        ->and(array_values($config)[0])->toEndWith('/config/laravel-zero.php')
        ->and($context)->toHaveCount(2)
        ->and(array_values($context))->each->toEndWith('.php')
        ->and($all)->toHaveCount(3);
});

it('bridges Laravel Zero configuration to Eloquent Zero', function (): void {
    expect(config('eloquent-zero.mode'))->toBe(config('laravel-zero.generation.mode'))
        ->and(config('eloquent-zero.model_search_directories'))->toBe(config('laravel-zero.generation.model_search_directories'))
        ->and(config('eloquent-zero.models'))->toBe(config('laravel-zero.generation.models'))
        ->and(config('eloquent-zero.tables'))->toBe(config('laravel-zero.generation.tables'))
        ->and(config('eloquent-zero.output_path'))->toBe(config('laravel-zero.generation.schema_path'))
        ->and(config('eloquent-zero.table_name_casing'))->toBe(config('laravel-zero.generation.table_name_casing'))
        ->and(config('eloquent-zero.column_name_casing'))->toBe(config('laravel-zero.generation.column_name_casing'))
        ->and(config('eloquent-zero.use_wayfinder'))->toBe(config('laravel-zero.generation.use_wayfinder'))
        ->and(config('eloquent-zero.connection'))->toBe(config('laravel-zero.database.connection'))
        ->and(config('eloquent-zero.allow_multiple_connections'))->toBe(config('laravel-zero.database.allow_multiple_connections'))
        ->and(config('eloquent-zero.publication_name'))->toBe(config('laravel-zero.database.publication_name'));
});

it('scaffolds the React provider and only missing globals', function (): void {
    $files = new Filesystem;
    $directory = sys_get_temp_dir().'/laravel-zero-'.uniqid();
    $provider = $directory.'/zero/provider.tsx';
    $globals = $directory.'/globals.ts';

    try {
        $files->ensureDirectoryExists($directory);
        $files->put($globals, "export const EXISTING = true;\nexport const ZERO_CACHE_URL = 'custom';\n");
        config()->set('laravel-zero.frontend', [
            'framework' => 'react',
            'provider_path' => $provider,
            'use_globals' => true,
            'globals_path' => $globals,
        ]);

        $changed = app(FrontendScaffolder::class)->scaffold();
        $globalContents = $files->get($globals);

        expect($changed)->toBe([$provider, $globals])
            ->and($files->get($provider))->toContain("from '@/globals'", 'useMemo<ZeroContext>')
            ->and($globalContents)->toContain("ZERO_CACHE_URL = 'custom'", 'VITE_ZERO_MUTATE_URL', 'VITE_ZERO_QUERY_URL')
            ->and(substr_count($globalContents, 'export const ZERO_CACHE_URL ='))->toBe(1)
            ->and(substr_count($globalContents, 'export const ZERO_MUTATE_URL ='))->toBe(1)
            ->and(substr_count($globalContents, 'export const ZERO_QUERY_URL ='))->toBe(1);

        $files->put($provider, 'custom provider');

        expect(app(FrontendScaffolder::class)->scaffold())->toBe([])
            ->and($files->get($provider))->toBe('custom provider')
            ->and($files->get($globals))->toBe($globalContents);
    } finally {
        $files->deleteDirectory($directory);
    }
});

it('scaffolds a React provider without globals when configured', function (): void {
    $files = new Filesystem;
    $directory = sys_get_temp_dir().'/laravel-zero-'.uniqid();
    $provider = $directory.'/provider.tsx';
    $globals = $directory.'/globals.ts';

    try {
        config()->set('laravel-zero.frontend', [
            'framework' => 'react',
            'provider_path' => $provider,
            'use_globals' => false,
            'globals_path' => $globals,
        ]);

        expect(app(FrontendScaffolder::class)->scaffold())->toBe([$provider])
            ->and($files->get($provider))->toContain('import.meta.env.VITE_ZERO_CACHE_URL')
            ->and($files->exists($globals))->toBeFalse();
    } finally {
        $files->deleteDirectory($directory);
    }
});
