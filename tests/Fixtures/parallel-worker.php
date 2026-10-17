<?php

declare(strict_types=1);

/**
 * One process of the parallel lookup test. Usage:
 *
 *   php parallel-worker.php <state directory> migrate
 *   php parallel-worker.php <state directory> lookup <search text>
 *
 * Every process has its own application, but they share an SQLite file, a file cache for the locks and a file that
 * counts the calls of the adapter, so the locks of the package are the only thing that keeps the calls apart.
 */

use Illuminate\Support\Facades\Artisan;
use Orchestra\Testbench\Foundation\Application;
use Taldres\GeocodeCache\Data\GeocodeQuery;
use Taldres\GeocodeCache\Facades\GeocodeCache;
use Taldres\GeocodeCache\GeocodeCacheServiceProvider;
use Taldres\GeocodeCache\Tests\Fixtures\CountingAdapter;

require __DIR__.'/../../vendor/autoload.php';

[, $directory, $mode] = $argv + [null, null, null];

$app = Application::create();
$app['config']->set([
    'app.key' => 'base64:'.base64_encode(str_repeat('k', 32)),
    'database.default' => 'parallel',
    'database.connections.parallel' => [
        'driver' => 'sqlite',
        'database' => "{$directory}/database.sqlite",
        'prefix' => '',
        'foreign_key_constraints' => true,
        'busy_timeout' => 20_000,
        'journal_mode' => 'WAL',
        'synchronous' => 'NORMAL',
    ],
    'cache.default' => 'parallel',
    'cache.stores.parallel' => ['driver' => 'file', 'path' => "{$directory}/cache", 'lock_path' => "{$directory}/cache"],
    'geocode-cache.driver' => 'counting',
    'geocode-cache.lock.wait' => 30,
    'geocode-cache.lock.seconds' => 30,
]);
$app->register(GeocodeCacheServiceProvider::class);

if ($mode === 'migrate') {
    touch("{$directory}/database.sqlite");
    Artisan::call('migrate', ['--path' => realpath(__DIR__.'/../../database/migrations'), '--realpath' => true, '--force' => true]);

    exit(0);
}

GeocodeCache::extend('counting', fn () => new CountingAdapter("{$directory}/calls.log"));

// Every process is booted before the first one asks, so they all reach the lock at the same moment.
touch("{$directory}/ready-".getmypid());

for ($waited = 0; ! is_file("{$directory}/go"); $waited++) {
    if ($waited > 6000) {
        fwrite(STDERR, "The start signal never came.\n");

        exit(2);
    }

    usleep(5_000);
}

try {
    $result = GeocodeCache::geocode(GeocodeQuery::make($argv[3] ?? 'Berlin'));

    echo json_encode(['latitude' => $result?->latitude, 'source' => $result?->source]);
} catch (Throwable $exception) {
    echo json_encode(['exception' => $exception::class]);

    exit(1);
}
