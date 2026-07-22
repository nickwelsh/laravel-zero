<?php

namespace NickWelsh\LaravelZero\Tests\Fixtures;

use NickWelsh\LaravelZero\Contracts\ZeroQueries;

final class BadQueries implements ZeroQueries
{
    public function byId(TestZeroContext $context, string $id)
    {
        return Party::zeroQuery()->where('id', app('ids')->resolve($id));
    }
}
