<?php

namespace NickWelsh\LaravelZero\Mutations;

use NickWelsh\LaravelZero\Contracts\ZeroDefaultMutation;

final class ZeroUpdateMutation implements ZeroDefaultMutation
{
    public static function operation(): string
    {
        return 'update';
    }
}
