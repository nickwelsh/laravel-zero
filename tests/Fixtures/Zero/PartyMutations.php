<?php

namespace NickWelsh\LaravelZero\Tests\Fixtures\Zero;

use Illuminate\Auth\Access\AuthorizationException;
use NickWelsh\LaravelZero\Attributes\ZeroMutationCollection;
use NickWelsh\LaravelZero\Contracts\ZeroMutations;
use NickWelsh\LaravelZero\Tests\Fixtures\CreatePartyInput;
use NickWelsh\LaravelZero\Tests\Fixtures\Party;
use NickWelsh\LaravelZero\Tests\Fixtures\TestZeroContext;
use RuntimeException;

#[ZeroMutationCollection('directory.party')]
final class PartyMutations implements ZeroMutations
{
    public function deny(TestZeroContext $context): void
    {
        throw new AuthorizationException('denied');
    }

    public function create(TestZeroContext $context, CreatePartyInput $input)
    {
        return Party::zeroMutate()
            ->serverOnly('reference_code')
            ->ignore('password_confirmation')
            ->create([
                ...$input->validated(),
                'user_id' => $context->user_id,
                'reference_code' => 'server-ref',
            ]);
    }

    public function createThenFail(TestZeroContext $context, CreatePartyInput $input): void
    {
        Party::zeroMutate()->ignore(['password_confirmation'])->create([
            ...$input->validated(),
            'user_id' => $context->user_id,
        ]);

        throw new RuntimeException('rollback me');
    }

    public function createPair(TestZeroContext $context, string $firstId, string $secondId): void
    {
        Party::zeroMutate()->create(['id' => $firstId, 'user_id' => $context->user_id, 'display_name' => 'First']);
        Party::zeroMutate()->create(['id' => $secondId, 'user_id' => $context->user_id, 'display_name' => 'Second']);
    }
}
