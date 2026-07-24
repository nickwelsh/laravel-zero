<?php

namespace NickWelsh\LaravelZero\Tests\Fixtures;

use NickWelsh\LaravelZero\Filters\ZeroFilterBuilder;
use NickWelsh\LaravelZero\Filters\ZeroFilterDefinition;
use NickWelsh\LaravelZero\Filters\ZeroFilterOperator;

final class PartyFilters extends ZeroFilterDefinition
{
    public function model(): string
    {
        return Party::class;
    }

    public function define(ZeroFilterBuilder $filter): void
    {
        $filter->string('name', 'display_name')
            ->label('Party name')
            ->operators(ZeroFilterOperator::Equals, ZeroFilterOperator::Contains);

        $filter->enum('kind', PartyKind::class, 'reference_code')
            ->label('Party type');

        $filter->relationship('emails', 'emailAddresses', EmailAddressFilters::class)
            ->label('Email addresses');
    }
}
