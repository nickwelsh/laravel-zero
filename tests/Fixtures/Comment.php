<?php

declare(strict_types=1);

namespace LaravelZero\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use LaravelZero\Attributes\ZeroAuthorize;
use LaravelZero\ContextField;

#[ZeroAuthorize('user_id', '=', ContextField::UserId)]
class Comment extends Model
{
    public $timestamps = false;

    public $incrementing = false;

    protected $keyType = 'string';
}
