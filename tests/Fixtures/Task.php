<?php

declare(strict_types=1);

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
use LaravelZero\Enums\FilterOperator;
use LaravelZero\Query\ZQLBuilder;

/**
 * @property string $id
 * @property string $user_id
 * @property string $title
 * @property bool $done
 * @property \Carbon\CarbonImmutable|null $completed_at
 * @property \Illuminate\Support\Carbon $created_at
 */
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

    public static function zero(): ZeroDefinition
    {
        return ZeroDefinition::make()
            ->allowedFilters([AllowedFilter::partial('title'), AllowedFilter::exact('done'), AllowedFilter::exact('completed_at'), AllowedFilter::belongsTo('list'), AllowedFilter::trashed(), AllowedFilter::operator('completed_after', FilterOperator::GREATER_THAN, 'completed_at'), AllowedFilter::operator('title_operator', FilterOperator::DYNAMIC, 'title'), AllowedFilter::beginsWithStrict('title_start', 'title'), AllowedFilter::groupOr('search', [AllowedFilter::partial('title'), AllowedFilter::exact('id')]), AllowedFilter::callback('is_completed', fn (ZQLBuilder $query): ZQLBuilder => $query->where('completed_at', 'IS NOT', null)), AllowedFilter::callback('title_is', fn (ZQLBuilder $query, mixed $value): ZQLBuilder => $query->where('title', $value), 'title')])
            ->allowedIncludes(['list', 'tags', 'comments'])
            ->allowedSorts(['title', 'created_at'])
            ->defaultSort('-created_at');
    }

    /**
     * @return BelongsTo<TaskList, $this>
     */
    public function list(): BelongsTo
    {
        return $this->belongsTo(TaskList::class, 'list_id');
    }

    /**
     * @return BelongsToMany<Tag, $this>
     */
    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class, 'task_tag', 'task_id', 'tag_id');
    }

    /**
     * @return HasMany<Comment, $this>
     */
    public function comments(): HasMany
    {
        return $this->hasMany(Comment::class, 'task_id');
    }

    protected function casts(): array
    {
        return ['done' => 'boolean', 'completed_at' => 'immutable_datetime'];
    }
}
