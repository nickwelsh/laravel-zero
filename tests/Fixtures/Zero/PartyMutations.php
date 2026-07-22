<?php

namespace NickWelsh\LaravelZero\Tests\Fixtures\Zero;

use NickWelsh\LaravelZero\Attributes\ZeroMutationCollection;
use NickWelsh\LaravelZero\Contracts\ZeroMutations;
use NickWelsh\LaravelZero\Tests\Fixtures\CreatePartyInput;
use NickWelsh\LaravelZero\Tests\Fixtures\Party;
use NickWelsh\LaravelZero\Tests\Fixtures\TestZeroContext;

#[ZeroMutationCollection('directory.party')]
final class PartyMutations implements ZeroMutations
{
    public function create(TestZeroContext $context, CreatePartyInput $input)
    {
        return Party::zeroMutate()
            ->serverOnly('reference_code')
            ->create([
                ...$input->validated(),
                'user_id' => $context->user_id,
                'reference_code' => 'server-ref',
            ]);
    }
}
