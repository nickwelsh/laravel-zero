<?php

use NickWelsh\LaravelZero\Tests\Fixtures\Party;

it('serves authoritative query AST', function (): void {
    $this->postJson('/zero/query', ['transform', [['id' => 'q1', 'name' => 'directory.party.byId', 'args' => ['party-1']]]])
        ->assertOk()->assertJsonPath('kind', 'QueryResponse')->assertJsonPath('userID', 'user-1')
        ->assertJsonPath('queries.0.ast.table', 'parties')->assertJsonPath('queries.0.ast.where.conditions.0.right.value', 'user-1');
});

it('serves recursive grid filters and rejects private filter fields', function (): void {
    $filter = [
        'type' => 'group',
        'combinator' => 'and',
        'children' => [
            ['type' => 'condition', 'field' => 'name', 'operator' => 'contains', 'value' => 'Acme'],
            ['type' => 'relationship', 'relationship' => 'emails', 'quantifier' => 'some'],
        ],
    ];

    $this->postJson('/zero/query', ['transform', [[
        'id' => 'q-grid',
        'name' => 'directory.party.grid',
        'args' => [['filter' => $filter, 'limit' => 25]],
    ]]])->assertOk()
        ->assertJsonPath('queries.0.ast.where.conditions.1.right.value', '%Acme%')
        ->assertJsonPath('queries.0.ast.where.conditions.2.op', 'EXISTS')
        ->assertJsonPath('queries.0.ast.limit', 25);

    $this->postJson('/zero/query', ['transform', [[
        'id' => 'q-private',
        'name' => 'directory.party.grid',
        'args' => [['filter' => [
            'type' => 'condition',
            'field' => 'user_id',
            'operator' => 'equals',
            'value' => 'attacker',
        ], 'limit' => 25]],
    ]]])->assertJsonPath('queries.0.error', 'parse');
});

it('returns structured query parse and application errors', function (): void {
    $this->postJson('/zero/query', ['bad'])->assertJsonPath('kind', 'TransformFailed')->assertJsonPath('reason', 'parse');
    $this->postJson('/zero/query', ['transform', [
        ['id' => 'q1', 'name' => 'directory.party.byId', 'args' => [123]],
        ['id' => 'q2', 'name' => 'missing', 'args' => []],
    ]])->assertJsonPath('queries.0.error', 'parse')->assertJsonPath('queries.1.error', 'app');
});

it('processes and deduplicates mutations', function (): void {
    $body = ['pushVersion' => 1, 'clientGroupID' => 'cg1', 'timestamp' => 1, 'requestID' => 'r1', 'mutations' => [[
        'type' => 'custom', 'id' => 1, 'clientID' => 'c1', 'name' => 'directory.party.create',
        'args' => [['id' => 'p1', 'display_name' => 'Party', 'password_confirmation' => 'Party']], 'timestamp' => 1,
    ]]];

    $this->postJson('/zero/mutate?schema=zero_0&appID=zero', $body)->assertOk()
        ->assertJsonPath('kind', 'MutateResponse')->assertJsonPath('mutations.0.id.id', 1);
    expect(Party::find('p1'))->not->toBeNull()->and(Party::find('p1')->user_id)->toBe('user-1');
    $this->postJson('/zero/mutate?schema=zero_0&appID=zero', $body)->assertJsonPath('mutations.0.result.error', 'alreadyProcessed');
});

it('persists application failures and advances mutation id', function (): void {
    $body = ['pushVersion' => 1, 'clientGroupID' => 'cg2', 'timestamp' => 1, 'requestID' => 'r2', 'mutations' => [[
        'type' => 'custom', 'id' => 1, 'clientID' => 'c2', 'name' => 'directory.party.create', 'args' => [['id' => 'p2']], 'timestamp' => 1,
    ]]];
    $this->postJson('/zero/mutate?schema=zero_0&appID=zero', $body)->assertJsonPath('mutations.0.result.error', 'app');
    $this->assertDatabaseHas('zero_0.clients', ['clientID' => 'c2', 'lastMutationID' => 1]);
    $this->assertDatabaseHas('zero_0.mutations', ['clientID' => 'c2', 'mutationID' => 1]);
});

it('persists authorization failures as processed application errors', function (): void {
    $body = ['pushVersion' => 1, 'clientGroupID' => 'auth-cg', 'timestamp' => 1, 'requestID' => 'auth-r', 'mutations' => [[
        'type' => 'custom', 'id' => 1, 'clientID' => 'auth-c', 'name' => 'directory.party.deny', 'args' => [], 'timestamp' => 1,
    ]]];

    $this->postJson('/zero/mutate?schema=zero_0&appID=zero', $body)
        ->assertJsonPath('mutations.0.result.error', 'app')->assertJsonPath('mutations.0.result.message', 'denied');
    $this->assertDatabaseHas('zero_0.clients', ['clientID' => 'auth-c', 'lastMutationID' => 1]);
});

it('rejects out-of-order mutations without advancing', function (): void {
    $body = ['pushVersion' => 1, 'clientGroupID' => 'cg3', 'timestamp' => 1, 'requestID' => 'r3', 'mutations' => [[
        'type' => 'custom', 'id' => 2, 'clientID' => 'c3', 'name' => 'directory.party.create',
        'args' => [['id' => 'p3', 'display_name' => 'Party']], 'timestamp' => 1,
    ]]];

    $this->postJson('/zero/mutate?schema=zero_0&appID=zero', $body)->assertJsonPath('kind', 'PushFailed')->assertJsonPath('reason', 'oooMutation');
    $this->assertDatabaseMissing('zero_0.clients', ['clientID' => 'c3']);
});

it('rolls back writes then persists application result', function (): void {
    $body = ['pushVersion' => 1, 'clientGroupID' => 'cg4', 'timestamp' => 1, 'requestID' => 'r4', 'mutations' => [[
        'type' => 'custom', 'id' => 1, 'clientID' => 'c4', 'name' => 'directory.party.createThenFail',
        'args' => [['id' => 'p4', 'display_name' => 'Party']], 'timestamp' => 1,
    ]]];

    $this->postJson('/zero/mutate?schema=zero_0&appID=zero', $body)->assertJsonPath('mutations.0.result.error', 'app');
    $this->assertDatabaseMissing('parties', ['id' => 'p4']);
    $this->assertDatabaseHas('zero_0.mutations', ['clientID' => 'c4', 'mutationID' => 1]);
});

it('processes multiple writes and mutations in order', function (): void {
    $body = ['pushVersion' => 1, 'clientGroupID' => 'cg5', 'timestamp' => 1, 'requestID' => 'r5', 'mutations' => [[
        'type' => 'custom', 'id' => 1, 'clientID' => 'c5', 'name' => 'directory.party.createPair',
        'args' => [['firstId' => 'p5a', 'secondId' => 'p5b']], 'timestamp' => 1,
    ], [
        'type' => 'custom', 'id' => 2, 'clientID' => 'c5', 'name' => 'directory.party.create',
        'args' => [['id' => 'p5c', 'display_name' => 'Third']], 'timestamp' => 1,
    ]]];

    $this->postJson('/zero/mutate?schema=zero_0&appID=zero', $body)->assertJsonCount(2, 'mutations');
    expect(Party::query()->orderBy('id')->pluck('id')->all())->toBe(['p5a', 'p5b', 'p5c']);
});

it('cleans acknowledged mutation results', function (): void {
    $this->app['db']->table('zero_0.mutations')->insert([
        'clientGroupID' => 'cg6', 'clientID' => 'c6', 'mutationID' => 1, 'result' => '{}',
    ]);
    $body = ['pushVersion' => 1, 'clientGroupID' => 'cg6', 'timestamp' => 1, 'requestID' => 'r6', 'mutations' => [[
        'type' => 'custom', 'id' => 99, 'clientID' => 'c6', 'name' => '_zero_cleanupResults',
        'args' => [['clientGroupID' => 'cg6', 'clientID' => 'c6', 'upToMutationID' => 1]], 'timestamp' => 1,
    ]]];

    $this->postJson('/zero/mutate?schema=zero_0&appID=zero', $body)->assertJsonCount(0, 'mutations');
    $this->assertDatabaseMissing('zero_0.mutations', ['clientID' => 'c6']);
});
