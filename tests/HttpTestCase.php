<?php

declare(strict_types=1);

namespace LaravelZero\Tests;

use LaravelZero\ZeroServiceProvider;

abstract class HttpTestCase extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [ZeroServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('zero.models', $this->models());
        $app['config']->set('zero.api_key', 'private-test-secret');
        $app['config']->set('zero.middleware', []);
    }
}
