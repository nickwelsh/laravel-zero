<?php

namespace NickWelsh\LaravelZero\Tests\Fixtures;

use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use NickWelsh\LaravelZero\Queries\AllowedFilter;
use NickWelsh\LaravelZero\Queries\ZeroQueryBuilder;

#[ScopedBy([PartyTenantScope::class])]
final class DynamicParty extends Model
{
    public $incrementing = false;

    public $timestamps = false;

    protected $table = 'parties';

    protected $keyType = 'string';

    public function zeroQueryBuilder(): ZeroQueryBuilder
    {
        return ZeroQueryBuilder::for($this)
            ->allowedFilters(AllowedFilter::exact('id'), 'display_name')
            ->allowedIncludes('emailAddresses', 'tags')
            ->allowedSorts('id', 'display_name');
    }

    public function emailAddresses(): HasMany
    {
        return $this->hasMany(EmailAddress::class, 'party_id');
    }

    public function tags(): MorphToMany
    {
        return $this->morphToMany(Tag::class, 'taggable');
    }
}
