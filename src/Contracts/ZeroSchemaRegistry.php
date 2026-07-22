<?php

namespace NickWelsh\LaravelZero\Contracts;

use NickWelsh\LaravelZero\Schema\ZeroModelSchema;

interface ZeroSchemaRegistry
{
    /** @param class-string $modelClass */
    public function model(string $modelClass): ZeroModelSchema;

    /** @return iterable<class-string, ZeroModelSchema> */
    public function models(): iterable;
}
