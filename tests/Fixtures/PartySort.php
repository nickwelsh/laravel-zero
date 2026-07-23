<?php

namespace NickWelsh\LaravelZero\Tests\Fixtures;

use NickWelsh\LaravelZero\Queries\ZeroQueryColumn;

enum PartySort: string implements ZeroQueryColumn
{
    case DisplayName = 'display_name';
    case Id = 'id';
}
