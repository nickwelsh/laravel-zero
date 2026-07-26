<?php

namespace NickWelsh\LaravelZero\Mutations;

use NickWelsh\LaravelZero\Contracts\ZeroDefaultMutation;

final class ZeroDeleteMutation implements ZeroDefaultMutation
{
    public static function operation(): string
    {
        return 'delete';
    }
}
