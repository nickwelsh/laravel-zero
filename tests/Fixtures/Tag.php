<?php

declare(strict_types=1);

namespace ZeroForLaravel\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use ZeroForLaravel\Attributes\ZeroAuthorize;
use ZeroForLaravel\ContextField;

#[ZeroAuthorize('user_id', '=', ContextField::UserId)]
class Tag extends Model
{
    public $timestamps = false;

    public $incrementing = false;

    protected $keyType = 'string';
}
