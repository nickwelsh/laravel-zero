<?php

namespace NickWelsh\LaravelZero\Mutations;

use NickWelsh\LaravelZero\Contracts\ZeroDefaultMutation;

final class ZeroCreateMutation implements ZeroDefaultMutation
{
    public static function operation(): string
    {
        return 'create';
    }
}
