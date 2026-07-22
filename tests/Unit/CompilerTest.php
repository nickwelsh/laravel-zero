<?php

use NickWelsh\LaravelZero\Compiler\Mutations\ZeroMutationCompiler;
use NickWelsh\LaravelZero\Compiler\Queries\ZeroQueryCompiler;
use NickWelsh\LaravelZero\Discovery\ZeroRegistry;
use NickWelsh\LaravelZero\Tests\Fixtures\FakeSchemaRegistry;

it('compiles scalar query and context filter', function (): void {
    $operation = app(ZeroRegistry::class)->query('directory.party.byId');
    $source = (new ZeroQueryCompiler(new FakeSchemaRegistry))->compile($operation);

    expect($source)->toContain('z.string()', 'zql.party.where("userId", ctx.user_id).where("id", args).one()');
});

it('compiles optimistic mutation omitting server-only field', function (): void {
    $operation = app(ZeroRegistry::class)->mutation('directory.party.create');
    $source = (new ZeroMutationCompiler(new FakeSchemaRegistry))->compile($operation);

    expect($source)->toContain('id: args.id', 'displayName: args.display_name', 'userId: ctx.user_id')->not->toContain('referenceCode');
});
