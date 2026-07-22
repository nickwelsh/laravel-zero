<?php

namespace NickWelsh\LaravelZero\Tests\Fixtures;

use NickWelsh\LaravelZero\Contracts\ZeroMutations;

final class BadMutations implements ZeroMutations
{
    public function create(TestZeroContext $context, CreatePartyInput $input)
    {
        return Party::zeroMutate()->create([
            'id' => $input->id,
            'user_id' => $context->user_id,
            'display_name' => app('names')->next(),
        ]);
    }
}
