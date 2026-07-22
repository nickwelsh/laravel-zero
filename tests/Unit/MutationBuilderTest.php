<?php

use NickWelsh\LaravelZero\Mutations\ZeroMutationBuilder;
use NickWelsh\LaravelZero\Tests\Fixtures\FakeSchemaRegistry;
use NickWelsh\LaravelZero\Tests\Fixtures\Party;

it('creates updates upserts and deletes Eloquent rows', function (): void {
    $builder = new ZeroMutationBuilder(new FakeSchemaRegistry, Party::class);
    $builder->create(['id' => 'p1', 'user_id' => 'u1', 'display_name' => 'One']);
    $builder->update(['id' => 'p1', 'display_name' => 'Updated']);
    expect(Party::find('p1')->display_name)->toBe('Updated');

    $builder->upsert(['id' => 'p1', 'user_id' => 'u1', 'display_name' => 'Upserted']);
    $builder->upsert(['id' => 'p2', 'user_id' => 'u1', 'display_name' => 'Two']);
    expect(Party::find('p1')->display_name)->toBe('Upserted')->and(Party::find('p2'))->not->toBeNull();

    expect($builder->delete(['id' => 'p2']))->toBeTrue()->and(Party::find('p2'))->toBeNull();
});
