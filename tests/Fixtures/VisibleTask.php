<?php

declare(strict_types=1);

namespace LaravelZero\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use LaravelZero\Attributes\ZeroPublic;
use LaravelZero\Attributes\ZeroQuery;

#[ZeroPublic]
#[ZeroQuery(['all', 'one'])]
class VisibleTask extends Model
{
    public $timestamps = false;

    protected $table = 'tasks';

    protected $visible = ['id', 'title'];

    protected $fillable = ['title', 'secret'];
}
