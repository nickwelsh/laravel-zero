<?php

namespace NickWelsh\LaravelZero\Tests\Fixtures;

use NickWelsh\LaravelZero\Filters\ZeroFilterBuilder;
use NickWelsh\LaravelZero\Filters\ZeroFilterDefinition;

final class EmailAddressFilters extends ZeroFilterDefinition
{
    public function model(): string
    {
        return EmailAddress::class;
    }

    public function define(ZeroFilterBuilder $filter): void
    {
        $filter->boolean('primary', 'is_primary')->label('Primary email');
        $filter->string('id')->label('Email ID');
    }
}
