<?php

namespace NickWelsh\LaravelZero\Tests\Fixtures;

use NickWelsh\LaravelZero\Queries\ZeroQueryColumn;

enum EmailAddressFilter: string implements ZeroQueryColumn
{
    case IsPrimary = 'is_primary';
}
