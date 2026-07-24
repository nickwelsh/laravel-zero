<?php

namespace NickWelsh\LaravelZero\Tests\Fixtures;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

final class PartyTenantScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $builder->where('user_id', 'tenant-1');
    }
}
