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

    public function filteredByEmail(TestZeroContext $context, EmailAddressFilter $field, bool $value): ZeroQueryBuilder
    {
        return Party::zeroQuery()->whereExists(
            'emailAddresses',
            fn (ZeroQueryBuilder $emailAddresses) => $emailAddresses->where($field, $value),
        );
    }

    public function withSortedEmails(
        TestZeroContext $context,
        EmailAddressSort $orderBy = EmailAddressSort::Id,
        ZeroOrderDirection $direction = ZeroOrderDirection::Asc,
    ): ZeroQueryBuilder {
        return Party::zeroQuery()->related(
            'emailAddresses',
            fn (ZeroQueryBuilder $emailAddresses) => $emailAddresses->orderBy($orderBy, $direction),
        );
    }

    public function mismatchedFilter(TestZeroContext $context, PartyGridInput $input): ZeroQueryBuilder
    {
        return EmailAddress::zeroQuery()->applyFilter($input->filter, PartyFilters::class);
    }

    public function unvalidatedFilter(TestZeroContext $context, PartyGridInput $input): ZeroQueryBuilder
    {
        return Party::zeroQuery()->applyFilter($input->limit, PartyFilters::class);
    }

    public function unsafe(TestZeroContext $context, string $orderBy): ZeroQueryBuilder
    {
        return Party::zeroQuery()->orderBy($orderBy);
    }
}
