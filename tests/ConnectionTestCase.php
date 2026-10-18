<?php

declare(strict_types=1);

namespace Taldres\GeocodeCache\Tests;

/**
 * Keeps the tables of the package on a connection of their own, an in-memory SQLite database that is independent of
 * the default connection, so these tests behave the same on every database of the CI matrix. The tables have names
 * that the default connection never has, and the tests create them here because the in-memory database is new in
 * every test, while RefreshDatabase migrates a real database only once.
 */
abstract class ConnectionTestCase extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('database.connections.geocode', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);
        $app['config']->set('geocode-cache.connection', 'geocode');
        $app['config']->set('geocode-cache.table', 'connection_cache');
        $app['config']->set('geocode-cache.statistics.table', 'connection_statistics');
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->app['db']->usingConnection('geocode', function (): void {
            foreach (glob(__DIR__.'/../database/migrations/*.php') ?: [] as $migration) {
                (require $migration)->up();
            }
        });
    }
}
