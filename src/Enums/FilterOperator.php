<?php

declare(strict_types=1);

namespace ZeroForLaravel\Enums;

enum FilterOperator: string
{
    case EQUAL = '=';
    case NOT_EQUAL = '!=';
    case GREATER_THAN = '>';
    case LESS_THAN = '<';
    case GREATER_THAN_OR_EQUAL = '>=';
    case LESS_THAN_OR_EQUAL = '<=';
    case DYNAMIC = 'dynamic';
}
