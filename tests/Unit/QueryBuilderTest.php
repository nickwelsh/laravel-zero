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
