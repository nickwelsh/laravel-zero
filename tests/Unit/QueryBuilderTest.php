<?php

use NickWelsh\LaravelZero\Queries\ZeroQueryBuilder;
use NickWelsh\LaravelZero\Tests\Fixtures\FakeSchemaRegistry;
use NickWelsh\LaravelZero\Tests\Fixtures\Party;

it('renders official Zero AST with server names', function (): void {
    $ast = (new ZeroQueryBuilder(new FakeSchemaRegistry, Party::class))
        ->where('user_id', 'user-1')->where('id', 'party-1')->one()->toAst();

    expect($ast)->toBe([
        'table' => 'parties',
        'where' => ['type' => 'and', 'conditions' => [
            ['type' => 'simple', 'op' => '=', 'left' => ['type' => 'column', 'name' => 'user_id'], 'right' => ['type' => 'literal', 'value' => 'user-1']],
            ['type' => 'simple', 'op' => '=', 'left' => ['type' => 'column', 'name' => 'id'], 'right' => ['type' => 'literal', 'value' => 'party-1']],
        ]],
        'limit' => 1,
    ]);
});

it('renders relationship correlation with server names', function (): void {
    $ast = (new ZeroQueryBuilder(new FakeSchemaRegistry, Party::class))
        ->related('emailAddresses', fn (ZeroQueryBuilder $query) => $query->where('is_primary', true)->limit(1))
        ->toAst();

    expect($ast['related'][0])->toBe([
        'system' => 'client',
        'correlation' => ['parentField' => ['id'], 'childField' => ['party_id']],
        'subquery' => [
            'table' => 'email_addresses',
            'alias' => 'emailAddresses',
            'where' => ['type' => 'simple', 'op' => '=', 'left' => ['type' => 'column', 'name' => 'is_primary'], 'right' => ['type' => 'literal', 'value' => true]],
            'limit' => 1,
        ],
    ]);
});
