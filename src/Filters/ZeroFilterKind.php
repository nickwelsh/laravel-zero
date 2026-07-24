<?php

namespace NickWelsh\LaravelZero\Filters;

enum ZeroFilterKind: string
{
    case String = 'string';
    case Number = 'number';
    case Boolean = 'boolean';
    case Date = 'date';
    case Enum = 'enum';
}
