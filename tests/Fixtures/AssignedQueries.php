<?php

namespace NickWelsh\LaravelZero\Tests\Fixtures;

use NickWelsh\LaravelZero\Contracts\ZeroQueries;

final class AssignedQueries implements ZeroQueries
{
    public function byId(TestZeroContext $context, string $id)
    {
        $query = Party::zeroQuery();
        $query = $query->where('user_id', $context->user_id);
        $query = $query->where('id', $id)->one();

        return $query;
    }
}
