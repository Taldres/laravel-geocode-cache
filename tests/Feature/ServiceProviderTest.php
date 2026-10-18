<?php

declare(strict_types=1);

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\AboutCommand;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Taldres\GeocodeCache\Adapters\GoogleAdapter;
use Taldres\GeocodeCache\CachedGeocoder;
use Taldres\GeocodeCache\Data\GeocodeQuery;
use Taldres\GeocodeCache\Facades\GeocodeCache;
use Taldres\GeocodeCache\GeocodeCacheManager;
use Taldres\GeocodeCache\GeocodeCacheServiceProvider;
use Taldres\GeocodeCache\Models\GeocodeCacheEntry;
use Taldres\GeocodeCache\Tests\Fixtures\FakeAdapter;

it('merges the default configuration', function () {
    expect(config('geocode-cache.driver'))->toBe('google')
        ->and(config('geocode-cache.table'))->toBe('geocode_cache')
        ->and(config('geocode-cache.connection'))->toBeNull()
        ->and(config('geocode-cache.ttl_days'))->toBe(29)
        ->and(config('geocode-cache.precision'))->toBeNull()
        ->and(config('geocode-cache.store_place_id'))->toBeTrue()
        ->and(config('geocode-cache.refresh.enabled'))->toBeFalse();
});

it('fills the keys that a configuration published earlier lacks', function () {
    config(['geocode-cache' => ['ttl_days' => 7, 'lock' => ['seconds' => 20], 'refresh' => ['enabled' => true]]]);

    (new GeocodeCacheServiceProvider(app()))->register();

    expect(config('geocode-cache.ttl_days'))->toBe(7)
        ->and(config('geocode-cache.lock.seconds'))->toBe(20)
        ->and(config('geocode-cache.lock.wait'))->toBe(10)
        ->and(config('geocode-cache.refresh.enabled'))->toBeTrue()
        ->and(config('geocode-cache.refresh.limit'))->toBe(100)
        ->and(config('geocode-cache.drivers.google.timeout'))->toBe(5)
        ->and(config('geocode-cache.source_ttl_days'))->toBe([])
        ->and(config('geocode-cache.refresh.store_query'))->toBeFalse();
});

it('registers the commands', function () {
    expect(Artisan::all())->toHaveKeys(['geocode-cache:prune', 'geocode-cache:refresh', 'geocode-cache:statistics']);
});

it('binds the manager as a singleton behind the facade', function () {
    expect(app(GeocodeCacheManager::class))->toBe(app(GeocodeCacheManager::class))
        ->and(GeocodeCache::getFacadeRoot())->toBe(app(GeocodeCacheManager::class));
});

it('publishes the configuration and the migration', function () {
    $config = config_path('geocode-cache.php');
    $migrations = glob(database_path('migrations/*_create_geocode_cache*.php')) ?: [];
    File::delete([$config, ...$migrations]);

    $this->artisan('vendor:publish', ['--tag' => 'geocode-cache'])->assertSuccessful();

    $published = glob(database_path('migrations/*_create_geocode_cache*.php')) ?: [];

    expect(File::exists($config))->toBeTrue()
        ->and($published)->toHaveCount(2)
        ->and(basename($published[0]))->not->toStartWith('2026_10_17_000000');

    File::delete([$config, ...$published]);
});

it('publishes the configuration and the migration under separate tags', function (string $tag, string $pattern) {
    $before = glob($pattern) ?: [];
    File::delete($before);

    $this->artisan('vendor:publish', ['--tag' => $tag])->assertSuccessful();

    $published = glob($pattern) ?: [];
    expect($published)->not->toBeEmpty();

    File::delete($published);
})->with([
    'config' => ['geocode-cache-config', fn () => config_path('geocode-cache.php')],
    'migrations' => ['geocode-cache-migrations', fn () => database_path('migrations/*_create_geocode_cache*.php')],
]);

it('creates the table under the configured name', function () {
    Schema::dropIfExists('geocode_cache');
    config(['geocode-cache.table' => 'address_coordinates']);

    (require __DIR__.'/../../database/migrations/2026_10_17_000000_create_geocode_cache_table.php')->up();

    expect(Schema::hasTable('address_coordinates'))->toBeTrue()
        ->and(Schema::hasTable('geocode_cache'))->toBeFalse()
        ->and(Schema::getColumnListing('address_coordinates'))->toBe([
            'id', 'query_hash', 'source', 'latitude', 'longitude', 'place_id', 'query_payload', 'fetched_at', 'expires_at', 'last_requested_at',
        ])
        ->and(new GeocodeCacheEntry)->getTable()->toBe('address_coordinates');
});

it('creates the statistics table under the configured name', function () {
    Schema::dropIfExists('geocode_cache_statistics');
    config(['geocode-cache.statistics.table' => 'address_requests']);

    (require __DIR__.'/../../database/migrations/2026_10_17_000001_create_geocode_cache_statistics_table.php')->up();

    expect(Schema::getColumnListing('address_requests'))->toBe(['id', 'query_hash', 'date', 'hits', 'lookups', 'refreshes'])
        ->and(collect(Schema::getIndexes('address_requests'))->contains(fn (array $index) => $index['columns'] === ['query_hash', 'date'] && $index['unique']))->toBeTrue();
});

it('drops the table under the configured name', function () {
    (require __DIR__.'/../../database/migrations/2026_10_17_000000_create_geocode_cache_table.php')->down();

    expect(Schema::hasTable('geocode_cache'))->toBeFalse();
});

it('indexes the hash uniquely and the expiry', function () {
    $indexes = collect(Schema::getIndexes('geocode_cache'));

    expect($indexes->contains(fn (array $index) => $index['columns'] === ['query_hash'] && $index['unique']))->toBeTrue()
        ->and($indexes->contains(fn (array $index) => $index['columns'] === ['expires_at']))->toBeTrue();
});

it('creates the drivers from the configuration', function () {
    expect(GeocodeCache::driver())->toBeInstanceOf(CachedGeocoder::class)
        ->and(GeocodeCache::driver()->adapter())->toBeInstanceOf(GoogleAdapter::class)
        ->and(GeocodeCache::driver('google')->adapter()->source())->toBe('google');
});

it('uses the configured default driver', function () {
    $adapter = FakeAdapter::returning();
    GeocodeCache::extend('custom', fn () => $adapter);
    config(['geocode-cache.driver' => 'custom']);

    expect(GeocodeCache::driver()->adapter())->toBe($adapter);
});

it('rejects an unknown driver', function () {
    GeocodeCache::driver('nope');
})->throws(InvalidArgumentException::class, 'Driver [nope] not supported.');

it('refuses a driver without an API key', function () {
    config(['geocode-cache.drivers.google.api_key' => null]);

    GeocodeCache::driver('google');
})->throws(InvalidArgumentException::class, 'API key');

it('wraps a custom adapter in a cached geocoder', function () {
    $adapter = FakeAdapter::returning();
    GeocodeCache::extend('custom', fn () => $adapter);

    $geocoder = GeocodeCache::driver('custom');

    expect($geocoder)->toBeInstanceOf(CachedGeocoder::class)
        ->and($geocoder->adapter())->toBe($adapter)
        ->and($geocoder->geocode('Berlin')->latitude)->toBe(52.52);
});

it('accepts a custom driver that already is a cached geocoder', function () {
    $geocoder = new CachedGeocoder(FakeAdapter::returning());
    GeocodeCache::extend('custom', fn () => $geocoder);

    expect(GeocodeCache::driver('custom'))->toBe($geocoder);
});

it('rejects a custom driver that is neither an adapter nor a cached geocoder', function () {
    GeocodeCache::extend('custom', fn () => new stdClass);

    GeocodeCache::driver('custom');
})->throws(InvalidArgumentException::class, 'must return a CachedGeocoder or an Adapter');

it('looks a query up through the facade and the cache', function () {
    Http::fake(['maps.googleapis.com/*' => Http::response([
        'status' => 'OK',
        'results' => [['geometry' => ['location' => ['lat' => 51.0504088, 'lng' => 13.7372621]], 'place_id' => 'dresden']],
    ])]);

    $first = GeocodeCache::geocode(GeocodeQuery::make('01067', 'DE'));
    $second = GeocodeCache::geocode(GeocodeQuery::make('01067', 'DE'));

    expect($first->latitude)->toBe(51.0504088)
        ->and($second->placeId)->toBe('dresden');
    Http::assertSentCount(1);
    $this->assertDatabaseCount('geocode_cache', 1);
});

it('reports whether the prune command is scheduled in the about command', function () {
    $this->artisan('about', ['--only' => 'geocode_cache'])
        ->expectsOutputToContain('NO, EXPIRED ENTRIES STAY IN THE TABLE')
        ->assertSuccessful();

    app(Schedule::class)->command('geocode-cache:prune')->hourly();

    $this->artisan('about', ['--only' => 'geocode_cache'])
        ->expectsOutputToContain('Prune Scheduled')
        ->doesntExpectOutputToContain('NO, EXPIRED ENTRIES STAY IN THE TABLE')
        ->assertSuccessful();
});

it('shows the settings in the about command as JSON', function () {
    Artisan::call('about', ['--only' => 'geocode_cache', '--json' => true]);

    $about = json_decode(Artisan::output(), true)['geocode_cache'];

    expect($about)->toMatchArray([
        'driver' => 'google',
        'table' => 'geocode_cache',
        'connection' => null,
        'retention' => 29,
        'precision' => null,
        'statistics' => false,
        'logging' => null,
        'prune_scheduled' => false,
        'refresh' => false,
    ]);
});

it('adds a section to the about command by default', function () {
    expect(config('geocode-cache.about'))->toBeTrue();

    Artisan::call('about', ['--json' => true]);

    expect(json_decode(Artisan::output(), true))->toHaveKey('geocode_cache');
});

it('leaves the about command alone when the section is switched off', function () {
    AboutCommand::flushState();
    config(['geocode-cache.about' => false]);
    $this->app->register(GeocodeCacheServiceProvider::class, force: true);

    Artisan::call('about', ['--json' => true]);

    expect(json_decode(Artisan::output(), true))->not->toHaveKey('geocode_cache');
});

it('shows the effective precision in the about command', function () {
    $this->artisan('about', ['--only' => 'geocode_cache'])->expectsOutputToContain('7 decimals')->assertSuccessful();

    config(['geocode-cache.precision' => 3]);
    $this->artisan('about', ['--only' => 'geocode_cache'])->expectsOutputToContain('3 decimals')->assertSuccessful();

    config(['geocode-cache.precision' => 9]);
    $this->artisan('about', ['--only' => 'geocode_cache'])->expectsOutputToContain('INVALID')->assertSuccessful();
});

it('does not call the precision invalid when the driver is not usable', function () {
    config(['geocode-cache.drivers.google.api_key' => '']);

    $this->artisan('about', ['--only' => 'geocode_cache'])
        ->expectsOutputToContain('UNKNOWN, NO USABLE DRIVER')
        ->doesntExpectOutputToContain('INVALID')
        ->assertSuccessful();
});

it('rejects a driver timeout that reaches the lifetime of the lock', function () {
    config(['geocode-cache.drivers.google.timeout' => 30, 'geocode-cache.lock.seconds' => 30]);

    expect(fn () => GeocodeCache::driver('google'))->toThrow(InvalidArgumentException::class, 'timeout');

    config(['geocode-cache.drivers.google.timeout' => 29]);
    app()->forgetInstance(GeocodeCacheManager::class);

    expect(GeocodeCache::driver('google'))->toBeInstanceOf(CachedGeocoder::class);
});

it('shows the retention that applies to the default driver in the about command', function () {
    $this->artisan('about', ['--only' => 'geocode_cache'])->expectsOutputToContain('29 days')->assertSuccessful();

    config(['geocode-cache.source_ttl_days' => ['google' => 10]]);
    $this->artisan('about', ['--only' => 'geocode_cache'])->expectsOutputToContain('10 days')->assertSuccessful();

    config(['geocode-cache.source_ttl_days' => ['google' => 31]]);
    $this->artisan('about', ['--only' => 'geocode_cache'])->expectsOutputToContain('INVALID')->assertSuccessful();
});

it('reports the retention that applies to the default driver as JSON', function () {
    config(['geocode-cache.source_ttl_days' => ['google' => 10]]);
    Artisan::call('about', ['--only' => 'geocode_cache', '--json' => true]);

    expect(json_decode(Artisan::output(), true)['geocode_cache']['retention'])->toBe(10);
});

it('reads the precision from the environment as a number', function () {
    foreach (['' => null, '4' => 4, '0' => 0, 'many' => 'many'] as $value => $expected) {
        $_ENV['GEOCODE_CACHE_PRECISION'] = $_SERVER['GEOCODE_CACHE_PRECISION'] = (string) $value;

        expect((require __DIR__.'/../../config/geocode-cache.php')['precision'])->toBe($expected);
    }

    unset($_ENV['GEOCODE_CACHE_PRECISION'], $_SERVER['GEOCODE_CACHE_PRECISION']);
});
