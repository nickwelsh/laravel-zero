<?php

use Illuminate\Support\ServiceProvider;
use NickWelsh\LaravelZero\LaravelZeroServiceProvider;

it('generates deterministically and checks freshness', function (): void {
    $this->artisan('zero:generate')->assertExitCode(0);
    $first = file_get_contents(config('laravel-zero.generation.output_directory').'/queries.generated.ts');
    $this->artisan('zero:generate')->expectsOutputToContain('unchanged')->assertExitCode(0);
    expect(file_get_contents(config('laravel-zero.generation.output_directory').'/queries.generated.ts'))->toBe($first);
    expect(file_get_contents(config('laravel-zero.generation.output_directory').'/manifest.generated.json'))->toContain('unique:parties,reference_code', '1.8.0');
    $this->artisan('zero:check')->assertExitCode(0);
});

it('publishes its config', function (): void {
    $paths = ServiceProvider::pathsToPublish(LaravelZeroServiceProvider::class, 'zero-config');

    expect($paths)->toHaveCount(1)
        ->and(array_key_first($paths))->toEndWith('/config/laravel-zero.php')
        ->and(array_values($paths)[0])->toEndWith('/config/laravel-zero.php');
});
