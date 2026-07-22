<?php

namespace NickWelsh\LaravelZero\Tests\Fixtures;

final readonly class TestZeroContext
{
    public function __construct(public string $user_id, public ?string $tenant_id = null) {}
}
