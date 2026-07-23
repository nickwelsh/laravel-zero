<?php

use NickWelsh\LaravelZero\Compiler\Diagnostics\ZeroCompilerException;
use NickWelsh\LaravelZero\Compiler\Mutations\ZeroMutationCompiler;
use NickWelsh\LaravelZero\Compiler\Queries\ZeroQueryCompiler;
use NickWelsh\LaravelZero\Discovery\Operation;
use NickWelsh\LaravelZero\Discovery\ZeroRegistry;
use NickWelsh\LaravelZero\Tests\Fixtures\AssignedQueries;
use NickWelsh\LaravelZero\Tests\Fixtures\BadMutations;
use NickWelsh\LaravelZero\Tests\Fixtures\BadQueries;
use NickWelsh\LaravelZero\Tests\Fixtures\FakeSchemaRegistry;

it('compiles scalar query and context filter', function (): void {
    $operation = app(ZeroRegistry::class)->query('directory.party.byId');
    $source = (new ZeroQueryCompiler(new FakeSchemaRegistry))->compile($operation);

    expect($source)->toContain('z.string()', 'zql.party.where("userId", ctx.user_id).where("id", args).one()');
});

it('compiles optimistic mutation omitting server-only and ignored fields', function (): void {
    $operation = app(ZeroRegistry::class)->mutation('directory.party.create');
    $source = (new ZeroMutationCompiler(new FakeSchemaRegistry))->compile($operation);

    expect($source)
        ->toContain('id: args.id', 'displayName: args.display_name', 'userId: ctx.user_id')
        ->not->toContain('referenceCode', 'password_confirmation');
});

it('compiles direct relationship callbacks', function (): void {
    $operation = app(ZeroRegistry::class)->query('directory.party.withPrimaryEmail');
    $source = (new ZeroQueryCompiler(new FakeSchemaRegistry))->compile($operation);

    expect($source)->toContain('.related("emailAddresses", query => query.where("isPrimary", true).limit(1))');
});

it('rejects non-portable query expressions with coded diagnostics', function (): void {
    $operation = new Operation('query', 'bad.byId', 'bad', BadQueries::class, new ReflectionMethod(BadQueries::class, 'byId'));

    expect(fn () => (new ZeroQueryCompiler(new FakeSchemaRegistry))->compile($operation))
        ->toThrow(ZeroCompilerException::class, 'ZERO-Q104');
});

it('compiles sequential query reassignment', function (): void {
    $operation = new Operation('query', 'assigned.byId', 'assigned', AssignedQueries::class, new ReflectionMethod(AssignedQueries::class, 'byId'));
    $source = (new ZeroQueryCompiler(new FakeSchemaRegistry))->compile($operation);

    expect($source)->toContain('zql.party.where("userId", ctx.user_id).where("id", args).one()');
});

it('rejects server dependencies in optimistic effects', function (): void {
    $operation = new Operation('mutation', 'bad.create', 'bad', BadMutations::class, new ReflectionMethod(BadMutations::class, 'create'));

    expect(fn () => (new ZeroMutationCompiler(new FakeSchemaRegistry))->compile($operation))
        ->toThrow(ZeroCompilerException::class, 'ZERO-M102');
});
