<?php

namespace NickWelsh\LaravelZero\Tests\Fixtures;

use NickWelsh\LaravelZero\Queries\ZeroQueryColumn;

enum EmailAddressSort: string implements ZeroQueryColumn
{
    case Id = 'id';
}
