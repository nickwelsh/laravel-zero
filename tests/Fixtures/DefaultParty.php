<?php

namespace NickWelsh\LaravelZero\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use NickWelsh\LaravelZero\Attributes\ZeroAuthorizeMutations;
use NickWelsh\LaravelZero\Attributes\ZeroDefaultMutations;
use NickWelsh\LaravelZero\Mutations\ZeroCreateMutation;
use NickWelsh\LaravelZero\Mutations\ZeroDeleteMutation;
use NickWelsh\LaravelZero\Mutations\ZeroUpdateMutation;

#[ZeroDefaultMutations([ZeroCreateMutation::class, ZeroUpdateMutation::class, ZeroDeleteMutation::class])]
#[ZeroAuthorizeMutations(DefaultPartyPolicy::class)]
final class DefaultParty extends Model
{
    public $incrementing = false;

    protected $table = 'parties';

    protected $keyType = 'string';

    public function tags(): MorphToMany
    {
        return $this->morphToMany(Tag::class, 'taggable');
    }
}
