<?php

namespace NickWelsh\LaravelZero\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use NickWelsh\LaravelZero\Support\HasZero;

final class Party extends Model
{
    use HasZero;

    public $incrementing = false;

    public $timestamps = false;

    protected $table = 'parties';

    protected $keyType = 'string';
}
