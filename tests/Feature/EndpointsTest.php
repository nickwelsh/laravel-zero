<?php

use NickWelsh\LaravelZero\Dynamic\DynamicMutationRegistry;
use NickWelsh\LaravelZero\Dynamic\DynamicQueryRegistry;
use NickWelsh\LaravelZero\Tests\Fixtures\DynamicParty;
use NickWelsh\LaravelZero\Tests\Fixtures\Party;
use NickWelsh\LaravelZero\Tests\Fixtures\TestUser;

it('serves authoritative query AST', function (): void {
    $this->postJson('/zero/query', ['transform', [['id' => 'q1', 'name' => 'directory.party.byId', 'args' => ['party-1']]]])
        ->assertOk()->assertJsonPath('kind', 'QueryResponse')->assertJsonPath('userID', 'user-1')
        ->assertJsonPath('queries.0.ast.table', 'parties')->assertJsonPath('queries.0.ast.where.conditions.0.right.value', 'user-1');
});

it('serves scoped dynamic queries and discriminator-safe morph includes', function (): void {
    config()->set('laravel-zero.generation.models', [DynamicParty::class]);
    app()->forgetInstance(DynamicQueryRegistry::class);

    $payload = ['transform', [[
        'id' => 'dynamic-party',
        'name' => 'models.dynamicParty',
        'args' => [[
            'filters' => [['field' => 'id', 'operator' => '=', 'value' => 'party-1']],
            'includes' => ['tags'],
            'orderBy' => [['display_name', 'desc']],
            'limit' => 20,
        ]],
    ]]];

    $this->postJson('/zero/query', $payload)->assertOk()
        ->assertJsonPath('queries.0.ast.where.conditions.0.left.name', 'user_id')
        ->assertJsonPath('queries.0.ast.where.conditions.0.right.value', 'tenant-1')
        ->assertJsonPath('queries.0.ast.where.conditions.1.left.name', 'id')
        ->assertJsonPath('queries.0.ast.related.0.subquery.table', 'taggables')
        ->assertJsonPath('queries.0.ast.related.0.subquery.where.left.name', 'taggable_type')
        ->assertJsonPath('queries.0.ast.related.0.subquery.where.right.value', DynamicParty::class)
        ->assertJsonPath('queries.0.ast.related.0.subquery.related.0.subquery.table', 'tags')
        ->assertJsonPath('queries.0.ast.orderBy.0.0', 'display_name');

    $payload = ['transform', [[
        'id' => 'dynamic-private',
        'name' => 'models.dynamicParty',
        'args' => [[
            'filters' => [['field' => 'user_id', 'operator' => '=', 'value' => 'attacker']],
        ]],
    ]]];

    $this->postJson('/zero/query', $payload)->assertJsonPath('queries.0.error', 'parse');
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

it('runs authorized default model and relationship mutations', function (): void {
    config()->set('laravel-zero.generation.models', [DynamicParty::class]);
    app()->forgetInstance(DynamicMutationRegistry::class);
    $this->actingAs(new TestUser);
    $this->app['db']->table('tags')->insert([
        ['id' => 'tag-1', 'name' => 'Important'],
        ['id' => 'tag-2', 'name' => 'Customer'],
    ]);

    $body = ['pushVersion' => 1, 'clientGroupID' => 'default-cg', 'timestamp' => 1, 'requestID' => 'default-r', 'mutations' => [[
        'type' => 'custom', 'id' => 1, 'clientID' => 'default-c', 'name' => 'models.dynamicParty.create',
        'args' => [[
            'values' => ['id' => 'default-party', 'userId' => 'tenant-1', 'displayName' => 'Created'],
            'relations' => [
                'emailAddresses' => [['id' => 'default-email', 'isPrimary' => true]],
                'tags' => ['tag-1'],
            ],
        ]], 'timestamp' => 1,
    ], [
        'type' => 'custom', 'id' => 2, 'clientID' => 'default-c', 'name' => 'models.dynamicParty.update',
        'args' => [['key' => ['id' => 'default-party'], 'values' => ['displayName' => 'Updated']]], 'timestamp' => 1,
    ], [
        'type' => 'custom', 'id' => 3, 'clientID' => 'default-c', 'name' => 'models.dynamicParty.relation',
        'args' => [['key' => ['id' => 'default-party'], 'relation' => 'tags', 'operation' => 'attach', 'ids' => 'tag-2']], 'timestamp' => 1,
    ]]];

    $this->postJson('/zero/mutate?schema=zero_0&appID=zero', $body)
        ->assertOk()
        ->assertJsonPath('kind', 'MutateResponse')
        ->assertJsonCount(3, 'mutations');
    $this->assertDatabaseHas('parties', ['id' => 'default-party', 'display_name' => 'Updated']);
    $this->assertDatabaseHas('email_addresses', ['id' => 'default-email', 'party_id' => 'default-party', 'is_primary' => true]);
    $this->assertDatabaseHas('taggables', [
        'tag_id' => 'tag-1',
        'taggable_type' => DynamicParty::class,
        'taggable_id' => 'default-party',
    ]);
    $this->assertDatabaseHas('taggables', [
        'tag_id' => 'tag-2',
        'taggable_type' => DynamicParty::class,
        'taggable_id' => 'default-party',
    ]);
});

it('rejects unauthenticated and cross-tenant default mutations on the server', function (): void {
    config()->set('laravel-zero.generation.models', [DynamicParty::class]);
    app()->forgetInstance(DynamicMutationRegistry::class);
    $this->app['db']->table('parties')->insert([
        'id' => 'protected-party',
        'user_id' => 'tenant-1',
        'display_name' => 'Protected',
    ]);

    $mutation = [
        'type' => 'custom', 'id' => 1, 'clientID' => 'denied-c', 'name' => 'models.dynamicParty.update',
        'args' => [['key' => ['id' => 'protected-party'], 'values' => ['displayName' => 'Compromised']]], 'timestamp' => 1,
    ];
    $body = ['pushVersion' => 1, 'clientGroupID' => 'denied-cg', 'timestamp' => 1, 'requestID' => 'denied-r', 'mutations' => [$mutation]];

    $this->postJson('/zero/mutate?schema=zero_0&appID=zero', $body)
        ->assertJsonPath('mutations.0.result.error', 'app')
        ->assertJsonPath('mutations.0.result.message', 'Unauthenticated.');

    app()->forgetInstance(DynamicMutationRegistry::class);
    $this->actingAs(new TestUser(tenantId: 'another-tenant'));
    $mutation['clientID'] = 'cross-tenant-c';
    $body['clientGroupID'] = 'cross-tenant-cg';
    $body['mutations'] = [$mutation];
    $this->postJson('/zero/mutate?schema=zero_0&appID=zero', $body)
        ->assertJsonPath('mutations.0.result.error', 'app');
    $this->assertDatabaseHas('parties', ['id' => 'protected-party', 'display_name' => 'Protected']);
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
