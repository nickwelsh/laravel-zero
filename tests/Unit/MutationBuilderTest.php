<?php

use NickWelsh\LaravelZero\Mutations\ZeroMutationBuilder;
use NickWelsh\LaravelZero\Tests\Fixtures\FakeSchemaRegistry;
use NickWelsh\LaravelZero\Tests\Fixtures\Party;

it('creates updates upserts and deletes Eloquent rows', function (): void {
    $builder = (new ZeroMutationBuilder(new FakeSchemaRegistry, Party::class))
        ->ignore('password_confirmation')
        ->ignore(['password_confirmation', 'unused']);

    expect($builder->ignoredFields())->toBe(['password_confirmation', 'unused']);

    $builder->create(['id' => 'p1', 'user_id' => 'u1', 'display_name' => 'One', 'password_confirmation' => 'One']);
    $builder->update(['id' => 'p1', 'display_name' => 'Updated', 'unused' => true]);
    expect(Party::find('p1')->display_name)->toBe('Updated');

    $builder->upsert(['id' => 'p1', 'user_id' => 'u1', 'display_name' => 'Upserted', 'password_confirmation' => 'Upserted']);
    $builder->upsert(['id' => 'p2', 'user_id' => 'u1', 'display_name' => 'Two', 'unused' => true]);
    expect(Party::find('p1')->display_name)->toBe('Upserted')->and(Party::find('p2'))->not->toBeNull();

    expect($builder->delete(['id' => 'p2', 'unused' => true]))->toBeTrue()->and(Party::find('p2'))->toBeNull();
});
