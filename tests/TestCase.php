<?php

declare(strict_types=1);

namespace Taldres\GeocodeCache\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use Taldres\GeocodeCache\GeocodeCacheServiceProvider;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [
            GeocodeCacheServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('k', 32)));
        $app['config']->set('geocode-cache.drivers.google.api_key', 'test-key');
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
    }
}
