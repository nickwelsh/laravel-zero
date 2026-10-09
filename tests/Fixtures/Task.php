<?php

namespace LaravelZero\Tests\Fixtures;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use LaravelZero\Attributes\ZeroAuthorize;
use LaravelZero\Attributes\ZeroMutate;
use LaravelZero\Attributes\ZeroQuery;
use LaravelZero\Attributes\ZeroServerFill;
use LaravelZero\ContextField;
use LaravelZero\Definition\AllowedFilter;
use LaravelZero\Definition\ZeroDefinition;

#[ZeroQuery(['all', 'one'])]
#[ZeroMutate('create', 'update', 'destroy', 'restore', 'force_delete')]
#[ZeroAuthorize('user_id', '=', ContextField::UserId)]
#[ZeroServerFill('user_id', ContextField::UserId)]
class Task extends Model
{
    use HasUuids, SoftDeletes;

    protected $table = 'tasks';

    protected $fillable = ['user_id', 'list_id', 'title', 'done', 'completed_at'];

    protected $hidden = ['secret'];

    protected function casts(): array
    {
        return ['done' => 'boolean', 'completed_at' => 'immutable_datetime'];
    }

    public static function zero(): ZeroDefinition
    {
        return ZeroDefinition::make()
            ->allowedFilters([AllowedFilter::partial('title'), AllowedFilter::exact('done'), AllowedFilter::exact('completed_at'), AllowedFilter::belongsTo('list'), AllowedFilter::trashed()])
            ->allowedIncludes(['list', 'tags', 'comments'])
            ->allowedSorts(['title', 'created_at'])
            ->defaultSort('-created_at');
    }

    public function list(): BelongsTo
    {
        return $this->belongsTo(TaskList::class, 'list_id');
    }

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class, 'task_tag', 'task_id', 'tag_id');
    }

    public function comments(): HasMany
    {
        return $this->hasMany(Comment::class, 'task_id');
    }
}
