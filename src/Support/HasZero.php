<?php

namespace NickWelsh\LaravelZero\Support;

use NickWelsh\LaravelZero\Contracts\ZeroSchemaRegistry;
use NickWelsh\LaravelZero\Mutations\ZeroMutationBuilder;
use NickWelsh\LaravelZero\Queries\ZeroQueryBuilder;

trait HasZero
{
    public static function zeroQuery(): ZeroQueryBuilder
    {
        return new ZeroQueryBuilder(app(ZeroSchemaRegistry::class), static::class);
    }

    public static function zeroMutate(): ZeroMutationBuilder
    {
        return new ZeroMutationBuilder(app(ZeroSchemaRegistry::class), static::class);
    }
}
