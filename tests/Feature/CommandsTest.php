<?php

it('generates deterministically and checks freshness', function (): void {
    $this->artisan('zero:generate')->assertExitCode(0);
    $first = file_get_contents(config('laravel-zero.generation.output_directory').'/queries.generated.ts');
    $this->artisan('zero:generate')->expectsOutputToContain('unchanged')->assertExitCode(0);
    expect(file_get_contents(config('laravel-zero.generation.output_directory').'/queries.generated.ts'))->toBe($first);
    $this->artisan('zero:check')->assertExitCode(0);
});
