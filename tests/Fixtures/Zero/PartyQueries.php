<?php

namespace NickWelsh\LaravelZero\Tests\Fixtures\Zero;

use NickWelsh\LaravelZero\Attributes\ZeroQueryCollection;
use NickWelsh\LaravelZero\Contracts\ZeroQueries;
use NickWelsh\LaravelZero\Tests\Fixtures\Party;
use NickWelsh\LaravelZero\Tests\Fixtures\PartyFilters;
use NickWelsh\LaravelZero\Tests\Fixtures\PartyGridInput;
use NickWelsh\LaravelZero\Tests\Fixtures\TestZeroContext;

#[ZeroQueryCollection('directory.party')]
final class PartyQueries implements ZeroQueries
{
    public function byIdWithArchived(TestZeroContext $context, string $id, bool $includeArchived = false)
    {
        return Party::zeroQuery()
            ->where('user_id', $context->user_id)
            ->where('id', $id);
    }

    public function byId(TestZeroContext $context, string $id)
    {
        return Party::zeroQuery()
            ->where('user_id', $context->user_id)
            ->where('id', $id)
            ->one();
    }

    public function grid(TestZeroContext $context, PartyGridInput $input)
    {
        return Party::zeroQuery()
            ->where('user_id', $context->user_id)
            ->applyFilter($input->filter, PartyFilters::class)
            ->limit($input->limit);
    }

    public function withPrimaryEmail(TestZeroContext $context, string $id)
    {
        return Party::zeroQuery()
            ->where('user_id', $context->user_id)
            ->where('id', $id)
            ->related('emailAddresses', fn ($query) => $query->where('is_primary', true)->limit(1))
            ->one();
    }
}
