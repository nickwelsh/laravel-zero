<?php

use NickWelsh\LaravelZero\Tests\Fixtures\Party;

it('serves authoritative query AST', function (): void {
    $this->postJson('/zero/query', [['id' => 'q1', 'name' => 'directory.party.byId', 'args' => ['party-1']]])
        ->assertOk()->assertJsonPath('0.ast.table', 'parties')->assertJsonPath('0.ast.where.conditions.0.right.value', 'user-1');
});

it('processes and deduplicates mutations', function (): void {
    $body = ['pushVersion' => 1, 'clientGroupID' => 'cg1', 'timestamp' => 1, 'requestID' => 'r1', 'mutations' => [[
        'type' => 'custom', 'id' => 1, 'clientID' => 'c1', 'name' => 'directory.party.create',
        'args' => [['id' => 'p1', 'display_name' => 'Party']], 'timestamp' => 1,
    ]]];

    $this->postJson('/zero/mutate?schema=public&appID=test', $body)->assertOk()
        ->assertJsonPath('kind', 'MutateResponse')->assertJsonPath('mutations.0.id.id', 1);
    expect(Party::find('p1'))->not->toBeNull()->and(Party::find('p1')->user_id)->toBe('user-1');
    $this->postJson('/zero/mutate?schema=public&appID=test', $body)->assertJsonPath('mutations.0.result.error', 'alreadyProcessed');
});

it('persists application failures and advances mutation id', function (): void {
    $body = ['pushVersion' => 1, 'clientGroupID' => 'cg2', 'timestamp' => 1, 'requestID' => 'r2', 'mutations' => [[
        'type' => 'custom', 'id' => 1, 'clientID' => 'c2', 'name' => 'directory.party.create', 'args' => [['id' => 'p2']], 'timestamp' => 1,
    ]]];
    $this->postJson('/zero/mutate?schema=public&appID=test', $body)->assertJsonPath('mutations.0.result.error', 'app');
    $this->assertDatabaseHas('zero_clients', ['client_id' => 'c2', 'last_mutation_id' => 1]);
    $this->assertDatabaseHas('zero_mutation_results', ['client_id' => 'c2', 'mutation_id' => 1]);
});
