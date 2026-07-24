<?php

namespace NickWelsh\LaravelZero\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;

final class Tag extends Model
{
    public $incrementing = false;

    public $timestamps = false;

    protected $keyType = 'string';
}
