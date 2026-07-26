<?php

namespace NickWelsh\LaravelZero\Tests\Fixtures;

use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use NickWelsh\LaravelZero\Attributes\ZeroAuthorizeMutations;
use NickWelsh\LaravelZero\Attributes\ZeroDefaultMutations;
use NickWelsh\LaravelZero\Mutations\ZeroCreateMutation;
use NickWelsh\LaravelZero\Mutations\ZeroDeleteMutation;
use NickWelsh\LaravelZero\Mutations\ZeroUpdateMutation;
use NickWelsh\LaravelZero\Queries\AllowedFilter;
use NickWelsh\LaravelZero\Queries\ZeroQueryBuilder;

#[ScopedBy([PartyTenantScope::class])]
#[ZeroDefaultMutations([ZeroCreateMutation::class, ZeroUpdateMutation::class, ZeroDeleteMutation::class])]
#[ZeroAuthorizeMutations(PartyPolicy::class)]
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
