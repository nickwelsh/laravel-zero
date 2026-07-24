<?php

use NickWelsh\LaravelZero\Queries\ZeroOrderDirection;
use NickWelsh\LaravelZero\Queries\ZeroQueryBuilder;
use NickWelsh\LaravelZero\Tests\Fixtures\EmailAddressFilter;
use NickWelsh\LaravelZero\Tests\Fixtures\EmailAddressSort;
use NickWelsh\LaravelZero\Tests\Fixtures\FakeSchemaRegistry;
use NickWelsh\LaravelZero\Tests\Fixtures\Party;
use NickWelsh\LaravelZero\Tests\Fixtures\PartyFilter;
use NickWelsh\LaravelZero\Tests\Fixtures\PartySort;

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

it('uses allowlisted column and direction enums at runtime', function (): void {
    $ast = (new ZeroQueryBuilder(new FakeSchemaRegistry, Party::class))
        ->where(PartyFilter::DisplayName, 'Acme')
        ->orderBy(PartySort::DisplayName, ZeroOrderDirection::Desc)
        ->toAst();

    expect($ast)
        ->toHaveKey('where.left.name', 'display_name')
        ->toHaveKey('orderBy', [['display_name', 'desc']]);
});

it('filters parent rows through allowlisted related fields', function (): void {
    $ast = (new ZeroQueryBuilder(new FakeSchemaRegistry, Party::class))
        ->whereExists('emailAddresses', fn (ZeroQueryBuilder $query) => $query->where(EmailAddressFilter::IsPrimary, true))
        ->toAst();

    expect($ast['where'])->toBe([
        'type' => 'correlatedSubquery',
        'related' => [
            'system' => 'client',
            'correlation' => ['parentField' => ['id'], 'childField' => ['party_id']],
            'subquery' => [
                'table' => 'email_addresses',
                'alias' => 'zsubq_emailAddresses',
                'where' => ['type' => 'simple', 'op' => '=', 'left' => ['type' => 'column', 'name' => 'is_primary'], 'right' => ['type' => 'literal', 'value' => true]],
            ],
        ],
        'op' => 'EXISTS',
    ]);
});

it('orders included relationship rows with allowlisted fields', function (): void {
    $ast = (new ZeroQueryBuilder(new FakeSchemaRegistry, Party::class))
        ->related('emailAddresses', fn (ZeroQueryBuilder $query) => $query->orderBy(EmailAddressSort::Id, ZeroOrderDirection::Desc))
        ->toAst();

    expect($ast)->toHaveKey('related.0.subquery.orderBy', [['id', 'desc']]);
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
