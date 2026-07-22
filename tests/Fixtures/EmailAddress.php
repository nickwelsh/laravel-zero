<?php

namespace NickWelsh\LaravelZero\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use NickWelsh\LaravelZero\Support\HasZero;

final class EmailAddress extends Model
{
    use HasZero;

    public $incrementing = false;

    public $timestamps = false;

    protected $table = 'email_addresses';

    protected $keyType = 'string';
}
