<?php

namespace NickWelsh\LaravelZero\Tests\Fixtures;

final class DefaultPartyPolicy
{
    public function create(TestUser $user): bool
    {
        return true;
    }

    public function update(TestUser $user, DefaultParty $party): bool
    {
        return true;
    }

    public function delete(TestUser $user, DefaultParty $party): bool
    {
        return true;
    }
}
