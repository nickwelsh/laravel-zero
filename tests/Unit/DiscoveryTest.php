<?php

use NickWelsh\LaravelZero\Compiler\Diagnostics\ZeroCompilerException;
use NickWelsh\LaravelZero\Discovery\ZeroRegistry;

it('discovers deterministic names', function (): void {
    expect(array_keys(app(ZeroRegistry::class)->queries()))->toBe([
        'directory.party.byId',
        'directory.party.byIdWithArchived',
        'directory.party.withPrimaryEmail',
    ]);
});

it('reports both duplicate operation locations', function (): void {
    config()->set('laravel-zero.discovery.directories', [__DIR__.'/../Fixtures/DuplicateZero']);
    app()->forgetInstance(ZeroRegistry::class);

    try {
        app(ZeroRegistry::class)->queries();
        $this->fail('Expected duplicate diagnostic.');
    } catch (ZeroCompilerException $error) {
        expect($error->diagnosticCode)->toBe('ZERO-D103')
            ->and($error->getMessage())->toContain('FirstQueries.php', 'SecondQueries.php');
    }
});
