<?php

namespace NickWelsh\LaravelZero\Tests\Fixtures\DuplicateZero;

use NickWelsh\LaravelZero\Attributes\ZeroQueryCollection;
use NickWelsh\LaravelZero\Contracts\ZeroQueries;
use NickWelsh\LaravelZero\Tests\Fixtures\Party;
use NickWelsh\LaravelZero\Tests\Fixtures\TestZeroContext;

#[ZeroQueryCollection('duplicate.party')]
final class SecondQueries implements ZeroQueries
{
    public function byId(TestZeroContext $context, string $id)
    {
        return Party::zeroQuery()->where('id', $id);
    }
}
