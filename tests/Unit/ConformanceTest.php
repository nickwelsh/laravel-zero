<?php

use NickWelsh\LaravelZero\Queries\ZeroQueryBuilder;
use NickWelsh\LaravelZero\Tests\Fixtures\FakeSchemaRegistry;
use NickWelsh\LaravelZero\Tests\Fixtures\Party;

it('keeps PHP query ASTs equal to TypeScript conformance fixtures', function (): void {
    $expected = json_decode(file_get_contents(__DIR__.'/../types/query-asts.json'), true, flags: JSON_THROW_ON_ERROR);
    $byId = (new ZeroQueryBuilder(new FakeSchemaRegistry, Party::class))
        ->where('user_id', 'user-1')->where('id', 'party-1')->one()->toAst(false);
    $related = (new ZeroQueryBuilder(new FakeSchemaRegistry, Party::class))
        ->where('user_id', 'user-1')->where('id', 'party-1')
        ->related('emailAddresses', fn (ZeroQueryBuilder $query) => $query->where('is_primary', true)->limit(1))
        ->one()->toAst(false);

    expect($byId)->toEqual($expected['byId'])->and($related)->toEqual($expected['withPrimaryEmail']);
});
