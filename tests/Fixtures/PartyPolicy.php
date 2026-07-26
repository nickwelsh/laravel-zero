<?php

namespace NickWelsh\LaravelZero\Tests\Fixtures;

final class PartyPolicy
{
    public function create(TestUser $user): bool
    {
        return $user->tenantId === 'tenant-1';
    }

    public function update(TestUser $user, DynamicParty $party): bool
    {
        return $user->tenantId === $party->user_id;
    }

    public function delete(TestUser $user, DynamicParty $party): bool
    {
        return $this->update($user, $party);
    }
}
