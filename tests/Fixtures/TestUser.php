<?php

namespace NickWelsh\LaravelZero\Tests\Fixtures;

use Illuminate\Foundation\Auth\User as Authenticatable;

final class TestUser extends Authenticatable
{
    public function __construct(
        public string $id = 'user-1',
        public string $tenantId = 'tenant-1',
    ) {
        parent::__construct();
    }
}
