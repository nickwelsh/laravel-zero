<?php

namespace NickWelsh\LaravelZero\Filters;

enum ZeroFilterCombinator: string
{
    case And = 'and';
    case Or = 'or';
}
