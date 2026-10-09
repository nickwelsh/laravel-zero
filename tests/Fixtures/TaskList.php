<?php

namespace LaravelZero\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LaravelZero\Attributes\ZeroAuthorize;
use LaravelZero\ContextField;
use LaravelZero\Definition\ZeroDefinition;

#[ZeroAuthorize('user_id', '=', ContextField::UserId)]
class TaskList extends Model
{
    protected $table = 'lists';

    public $timestamps = false;

    public $incrementing = false;

    protected $keyType = 'string';

    public static function zero(): ZeroDefinition
    {
        return ZeroDefinition::make()->allowedIncludes(['tasks']);
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class, 'list_id');
    }
}
