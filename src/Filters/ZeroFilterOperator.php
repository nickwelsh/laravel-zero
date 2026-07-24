<?php

namespace NickWelsh\LaravelZero\Filters;

enum ZeroFilterOperator: string
{
    case Equals = 'equals';
    case NotEquals = 'not_equals';
    case Contains = 'contains';
    case NotContains = 'not_contains';
    case StartsWith = 'starts_with';
    case EndsWith = 'ends_with';
    case GreaterThan = 'greater_than';
    case GreaterThanOrEqual = 'greater_than_or_equal';
    case LessThan = 'less_than';
    case LessThanOrEqual = 'less_than_or_equal';
    case In = 'in';
    case NotIn = 'not_in';
    case IsNull = 'is_null';
    case IsNotNull = 'is_not_null';

    public function requiresValue(): bool
    {
        return ! in_array($this, [self::IsNull, self::IsNotNull], true);
    }

    public function requiresList(): bool
    {
        return in_array($this, [self::In, self::NotIn], true);
    }
}
