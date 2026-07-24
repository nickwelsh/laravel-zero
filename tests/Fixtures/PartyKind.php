<?php

namespace NickWelsh\LaravelZero\Tests\Fixtures;

enum PartyKind: string
{
    case Person = 'person';
    case Company = 'company';
    case Household = 'household';
}
