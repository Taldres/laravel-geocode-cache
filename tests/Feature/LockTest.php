<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Sleep;
use Taldres\GeocodeCache\CachedGeocoder;
use Taldres\GeocodeCache\Data\Coordinates;
use Taldres\GeocodeCache\Data\GeocodeQuery;
use Taldres\GeocodeCache\Exceptions\GeocodingFailedException;
use Taldres\GeocodeCache\Models\GeocodeCacheEntry;
use Taldres\GeocodeCache\Tests\Fixtures\FakeAdapter;

beforeEach(function () {
    $this->travelTo('2026-10-17 12:00:00');
    config(['geocode-cache.lock.wait' => 2]);
    Sleep::fake(syncWithCarbon: true);
});

function lockKey(string $search): string
{
    return 'geocode-cache:'.GeocodeQuery::make($search)->hash('google');
}

it('takes the result of a parallel lookup instead of asking the service again', function () {
    $adapter = FakeAdapter::returning(1.0, 2.0);
    $other = Cache::lock(lockKey('Berlin'), 30);
    $other->get();

    // While this request waits, the other process stores its result and releases the lock.
    Sleep::whenFakingSleep(function () use ($other) {
        GeocodeCacheEntry::query()->create([
            'query_hash' => GeocodeQuery::make('Berlin')->hash('google'),
            'source' => 'google',
            'latitude' => 9.0,
            'longitude' => 8.0,
            'fetched_at' => now(),
            'expires_at' => now()->addDays(29),
            'last_requested_at' => now(),
        ]);
        $other->release();
    });

    $result = (new CachedGeocoder($adapter))->geocode('Berlin');

    expect($result->latitude)->toBe(9.0)
        ->and($adapter->calls())->toBe(0);
});

it('fails instead of asking the service when the lock stays taken', function () {
    $adapter = FakeAdapter::returning();
    Cache::lock(lockKey('Berlin'), 60)->get();

    expect(fn () => (new CachedGeocoder($adapter))->geocode('Berlin'))
        ->toThrow(GeocodingFailedException::class, 'Another process is still looking up the same query')
        ->and($adapter->calls())->toBe(0);
});

it('does not wait for the lock of another query', function () {
    Cache::lock(lockKey('Hamburg'), 60)->get();
    $adapter = FakeAdapter::returning();

    expect((new CachedGeocoder($adapter))->geocode('Berlin'))->not->toBeNull()
        ->and($adapter->calls())->toBe(1);
});

it('releases the lock after a lookup', function () {
    (new CachedGeocoder(FakeAdapter::returning()))->geocode('Berlin');

    expect(Cache::lock(lockKey('Berlin'), 5)->get())->toBeTrue();
});

it('releases the lock when the lookup fails', function () {
    $geocoder = new CachedGeocoder(new FakeAdapter([fn () => throw GeocodingFailedException::connection()]));

    expect(fn () => $geocoder->geocode('Berlin'))->toThrow(GeocodingFailedException::class)
        ->and(Cache::lock(lockKey('Berlin'), 5)->get())->toBeTrue();
});

it('does not take the lock when the cache can answer', function () {
    $geocoder = new CachedGeocoder(FakeAdapter::returning());
    $geocoder->geocode('Berlin');

    Cache::lock(lockKey('Berlin'), 60)->get();

    expect($geocoder->geocode('Berlin'))->not->toBeNull();
});

it('keeps the locks in the configured cache store', function () {
    config([
        'cache.stores.locks' => ['driver' => 'array'],
        'geocode-cache.lock.store' => 'locks',
    ]);
    Cache::store('locks')->lock(lockKey('Berlin'), 60)->get();

    expect(fn () => (new CachedGeocoder(FakeAdapter::returning()))->geocode('Berlin'))
        ->toThrow(GeocodingFailedException::class, 'Another process');
});

it('stores a result only once for parallel lookups', function () {
    $adapter = new FakeAdapter([new Coordinates(1.0, 2.0)]);
    $geocoder = new CachedGeocoder($adapter);

    $geocoder->geocode('Berlin');
    $geocoder->geocode('Berlin');

    expect($adapter->calls())->toBe(1);
    $this->assertDatabaseCount('geocode_cache', 1);
});

it('uses the default cache store for a blank lock store', function () {
    config(['geocode-cache.lock.store' => '']);

    expect((new CachedGeocoder(FakeAdapter::returning()))->geocode('Berlin'))->not->toBeNull();
});

it('rejects lock settings that would never free a lock or never wait', function (string $setting, int $value) {
    config(["geocode-cache.lock.{$setting}" => $value]);
    $adapter = FakeAdapter::returning();

    expect(fn () => (new CachedGeocoder($adapter))->geocode('Berlin'))->toThrow(InvalidArgumentException::class, 'lock.seconds')
        ->and($adapter->calls())->toBe(0);
})->with([
    'no lifetime' => ['seconds', 0],
    'negative wait' => ['wait', -1],
]);
