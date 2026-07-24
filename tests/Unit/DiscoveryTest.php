<?php

use NickWelsh\LaravelZero\Compiler\Diagnostics\ZeroCompilerException;
use NickWelsh\LaravelZero\Discovery\ZeroRegistry;

it('discovers deterministic names', function (): void {
    expect(array_keys(app(ZeroRegistry::class)->queries()))->toBe([
        'directory.party.byId',
        'directory.party.byIdWithArchived',
        'directory.party.grid',
        'directory.party.withPrimaryEmail',
    ]);
});

it('discovers queries and mutators from their own directories', function (): void {
    config()->set('laravel-zero.discovery.queries', []);
    config()->set('laravel-zero.discovery.mutators', [__DIR__.'/../Fixtures/Zero']);
    app()->forgetInstance(ZeroRegistry::class);

    $registry = app(ZeroRegistry::class);

    expect($registry->queries())->toBe([])
        ->and($registry->mutations())->not->toBeEmpty();
});

it('reports both duplicate operation locations', function (): void {
    config()->set('laravel-zero.discovery.queries', [__DIR__.'/../Fixtures/DuplicateZero']);
    app()->forgetInstance(ZeroRegistry::class);

    try {
        app(ZeroRegistry::class)->queries();
        $this->fail('Expected duplicate diagnostic.');
    } catch (ZeroCompilerException $error) {
        expect($error->diagnosticCode)->toBe('ZERO-D103')
            ->and($error->getMessage())->toContain('FirstQueries.php', 'SecondQueries.php');
    }
});
