<?php

use NickWelsh\LaravelZero\Queries\ZeroQueryBuilder;
use NickWelsh\LaravelZero\Tests\Fixtures\FakeSchemaRegistry;
use NickWelsh\LaravelZero\Tests\Fixtures\Party;
use NickWelsh\LaravelZero\Tests\Fixtures\PartyFilters;

it('keeps PHP query ASTs equal to TypeScript conformance fixtures', function (): void {
    $expected = json_decode(file_get_contents(__DIR__.'/../types/query-asts.json'), true, flags: JSON_THROW_ON_ERROR);
    $byId = (new ZeroQueryBuilder(new FakeSchemaRegistry, Party::class))
        ->where('user_id', 'user-1')->where('id', 'party-1')->one()->toAst(false);
    $exists = (new ZeroQueryBuilder(new FakeSchemaRegistry, Party::class))
        ->whereExists('emailAddresses', fn (ZeroQueryBuilder $query) => $query->where('is_primary', true))
        ->toAst(false);
    $grid = (new ZeroQueryBuilder(new FakeSchemaRegistry, Party::class))
        ->where('user_id', 'user-1')
        ->applyFilter([
            'type' => 'group',
            'combinator' => 'and',
            'children' => [
                ['type' => 'condition', 'field' => 'name', 'operator' => 'contains', 'value' => 'Acme'],
                [
                    'type' => 'group',
                    'combinator' => 'or',
                    'children' => [
                        ['type' => 'condition', 'field' => 'kind', 'operator' => 'equals', 'value' => 'person'],
                        ['type' => 'condition', 'field' => 'kind', 'operator' => 'equals', 'value' => 'company'],
                    ],
                ],
                [
                    'type' => 'relationship',
                    'relationship' => 'emails',
                    'quantifier' => 'some',
                    'filter' => ['type' => 'condition', 'field' => 'primary', 'operator' => 'equals', 'value' => true],
                ],
            ],
        ], PartyFilters::class)
        ->limit(25)
        ->toAst(false);
    $related = (new ZeroQueryBuilder(new FakeSchemaRegistry, Party::class))
        ->where('user_id', 'user-1')->where('id', 'party-1')
        ->related('emailAddresses', fn (ZeroQueryBuilder $query) => $query->where('is_primary', true)->limit(1))
        ->one()->toAst(false);

    expect($byId)->toEqual($expected['byId'])
        ->and($exists)->toEqual($expected['withPrimaryEmailExists'])
        ->and($grid)->toEqual($expected['grid'])
        ->and($related)->toEqual($expected['withPrimaryEmail']);
});
