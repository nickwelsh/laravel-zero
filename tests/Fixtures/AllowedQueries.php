<?php

namespace NickWelsh\LaravelZero\Tests\Fixtures;

use NickWelsh\LaravelZero\Queries\ZeroOrderDirection;
use NickWelsh\LaravelZero\Queries\ZeroQueryBuilder;

final class AllowedQueries
{
    public function paginated(
        TestZeroContext $context,
        int $limit,
        PartySort $orderBy = PartySort::DisplayName,
        ZeroOrderDirection $direction = ZeroOrderDirection::Asc,
    ): ZeroQueryBuilder {
        return Party::zeroQuery()
            ->limit($limit)
            ->orderBy($orderBy, $direction);
    }

    public function filtered(TestZeroContext $context, PartyFilter $field, string $value): ZeroQueryBuilder
    {
        return Party::zeroQuery()->where($field, $value);
    }

    public function unsafe(TestZeroContext $context, string $orderBy): ZeroQueryBuilder
    {
        return Party::zeroQuery()->orderBy($orderBy);
    }
}
