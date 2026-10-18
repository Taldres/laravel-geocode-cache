<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Taldres\GeocodeCache\CachedGeocoder;
use Taldres\GeocodeCache\Data\Coordinates;
use Taldres\GeocodeCache\Exceptions\InvalidConfigurationException;
use Taldres\GeocodeCache\Facades\GeocodeCache;
use Taldres\GeocodeCache\Models\GeocodeCacheEntry;
use Taldres\GeocodeCache\Models\GeocodeCacheStatistic;
use Taldres\GeocodeCache\Tests\Fixtures\FakeAdapter;

beforeEach(function () {
    $this->travelTo('2026-10-17 12:00:00');
});

function rows(string $table, string $connection = 'geocode'): int
{
    return DB::connection($connection)->table($table)->count();
}

function memoryConnection(): array
{
    return ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true];
}

function createTablesOn(string $connection): void
{
    DB::usingConnection($connection, function (): void {
        foreach (glob(__DIR__.'/../../database/migrations/*.php') ?: [] as $migration) {
            (require $migration)->up();
        }
    });
}

it('keeps both tables on the configured connection and not on the default one', function () {
    expect(Schema::connection('geocode')->hasTable('connection_cache'))->toBeTrue()
        ->and(Schema::connection('geocode')->hasTable('connection_statistics'))->toBeTrue()
        ->and(Schema::hasTable('connection_cache'))->toBeFalse()
        ->and(Schema::hasTable('connection_statistics'))->toBeFalse();
});

it('tells the migrations and the models which connection to use', function (string $migration) {
    $migration = require __DIR__.'/../../database/migrations/'.$migration;

    expect($migration->getConnection())->toBe('geocode')
        ->and((new GeocodeCacheEntry)->getConnectionName())->toBe('geocode')
        ->and((new GeocodeCacheStatistic)->getConnectionName())->toBe('geocode');

    config(['geocode-cache.connection' => '']);

    expect($migration->getConnection())->toBeNull()
        ->and((new GeocodeCacheEntry)->getConnectionName())->toBeNull()
        ->and((new GeocodeCacheStatistic)->getConnectionName())->toBeNull();

    config(['geocode-cache.connection' => null]);

    expect($migration->getConnection())->toBeNull();
})->with([
    'cache table' => '2026_10_17_000000_create_geocode_cache_table.php',
    'statistics table' => '2026_10_17_000001_create_geocode_cache_statistics_table.php',
]);

it('stores, serves and deletes entries on the configured connection', function () {
    $adapter = new FakeAdapter([new Coordinates(1.0, 2.0, 'place-1'), new Coordinates(3.0, 4.0, 'place-2')]);
    $geocoder = new CachedGeocoder($adapter);

    $geocoder->geocode('Berlin');
    $geocoder->geocode('Berlin');

    expect($adapter->calls())->toBe(1)
        ->and(rows('connection_cache'))->toBe(1)
        ->and(GeocodeCacheEntry::query()->count())->toBe(1);

    $this->travel(30)->days();

    expect($geocoder->geocode('Berlin')?->placeId)->toBe('place-2')
        ->and($adapter->calls())->toBe(2)
        ->and(rows('connection_cache'))->toBe(1);

    $this->travel(30)->days();

    $this->artisan('geocode-cache:prune')
        ->expectsOutputToContain('1 expired geocode cache entries deleted.')
        ->assertSuccessful();

    expect(rows('connection_cache'))->toBe(0);
});

it('counts the requests on the configured connection', function () {
    config(['geocode-cache.statistics.enabled' => true]);
    $geocoder = new CachedGeocoder(FakeAdapter::returning());

    $geocoder->geocode('Berlin');
    $geocoder->geocode('Berlin');

    expect(DB::connection('geocode')->table('connection_statistics')->first())
        ->hits->toBe(1)
        ->lookups->toBe(1);

    $this->artisan('geocode-cache:statistics')->assertSuccessful();
});

it('refreshes entries on the configured connection', function () {
    config([
        'geocode-cache.refresh.enabled' => true,
        'geocode-cache.refresh.store_query' => true,
        'geocode-cache.refresh.requested_within_days' => 30,
        'geocode-cache.driver' => 'fake',
    ]);
    $adapter = new FakeAdapter([new Coordinates(1.0, 2.0, 'place-1'), new Coordinates(5.0, 6.0, 'place-1')]);
    GeocodeCache::extend('fake', fn () => $adapter);

    GeocodeCache::geocode('Berlin');
    $this->travel(27)->days();

    $this->artisan('geocode-cache:refresh')->assertSuccessful();

    $entry = GeocodeCacheEntry::query()->firstOrFail();

    expect($adapter->calls())->toBe(2)
        ->and($entry->latitude)->toBe(5.0)
        ->and($entry->fetched_at->equalTo(now()))->toBeTrue();
});

it('rejects a connection that is not defined, before the service is called', function (mixed $connection) {
    config(['geocode-cache.connection' => $connection]);
    $adapter = FakeAdapter::returning();

    expect(fn () => (new CachedGeocoder($adapter))->geocode('Berlin'))->toThrow(InvalidConfigurationException::class)
        ->and($adapter->calls())->toBe(0);
})->with([
    'unknown name' => 'missing',
    'not a string' => 123,
]);

it('accepts a connection name with a dot and a connection registered at runtime', function (string $name, bool $runtime) {
    if ($runtime) {
        DB::connectUsing($name, memoryConnection());
    } else {
        config(['database.connections' => [...config('database.connections'), $name => memoryConnection()]]);
    }

    config(['geocode-cache.connection' => $name]);
    createTablesOn($name);

    (new CachedGeocoder(FakeAdapter::returning()))->geocode('Berlin');

    expect(rows('connection_cache', $name))->toBe(1);
})->with([
    'a dot in the name' => ['tenant.shared', false],
    'registered at runtime' => ['runtime', true],
]);

it('lets a model name its own connection', function () {
    $model = new class extends GeocodeCacheEntry
    {
        protected $connection = 'elsewhere';
    };

    expect($model->getConnectionName())->toBe('elsewhere');
});

it('keeps the entries when the transaction of the application is rolled back', function () {
    DB::beginTransaction();
    (new CachedGeocoder(FakeAdapter::returning()))->geocode('Berlin');
    DB::rollBack();

    expect(rows('connection_cache'))->toBe(1);
});

it('shows the connection in the about command', function () {
    Artisan::call('about', ['--only' => 'geocode_cache', '--json' => true]);

    expect(json_decode(Artisan::output(), true)['geocode_cache'])->toMatchArray(['connection' => 'geocode']);

    $this->artisan('about', ['--only' => 'geocode_cache'])->expectsOutputToContain('geocode')->assertSuccessful();
});
