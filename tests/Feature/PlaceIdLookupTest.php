<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Taldres\GeocodeCache\CachedGeocoder;
use Taldres\GeocodeCache\Data\Coordinates;
use Taldres\GeocodeCache\Data\GeocodeQuery;
use Taldres\GeocodeCache\Data\PlaceIdQuery;
use Taldres\GeocodeCache\Facades\GeocodeCache;
use Taldres\GeocodeCache\Models\GeocodeCacheEntry;
use Taldres\GeocodeCache\Tests\Fixtures\FakeAdapter;
use Taldres\GeocodeCache\Tests\Fixtures\FakePlaceAdapter;

beforeEach(function () {
    $this->travelTo('2026-10-17 12:00:00');
});

it('asks the adapter for a place ID without an entry and stores the coordinates', function () {
    $adapter = new FakePlaceAdapter([new Coordinates(52.52, 13.405, 'ChIJ-abc')]);
    $result = (new CachedGeocoder($adapter))->geocode(PlaceIdQuery::make('ChIJ-abc'));

    expect($adapter->calls())->toBe(1)
        ->and($adapter->placeQueries[0]->placeId)->toBe('ChIJ-abc')
        ->and($result->latitude)->toBe(52.52)
        ->and($result->placeId)->toBe('ChIJ-abc')
        ->and($result->expiresAt->equalTo(now()->addDays(29)))->toBeTrue();

    $this->assertDatabaseCount('geocode_cache', 1);
    $this->assertDatabaseHas('geocode_cache', ['place_id' => 'ChIJ-abc', 'latitude' => 52.52]);
});

it('answers from the cache while the entry is valid and never extends it', function () {
    $adapter = new FakePlaceAdapter([new Coordinates(52.52, 13.405, 'ChIJ-abc')]);
    $geocoder = new CachedGeocoder($adapter);
    $first = $geocoder->geocode(PlaceIdQuery::make('ChIJ-abc'));

    $this->travel(10)->days();
    $second = $geocoder->geocode(PlaceIdQuery::make('ChIJ-abc'));

    expect($adapter->calls())->toBe(1)
        ->and($second->expiresAt->equalTo($first->expiresAt))->toBeTrue();
});

it('deletes an expired entry and asks again', function () {
    $adapter = new FakePlaceAdapter([new Coordinates(1.0, 2.0, 'ChIJ-abc'), new Coordinates(3.0, 4.0, 'ChIJ-abc')]);
    $geocoder = new CachedGeocoder($adapter);
    $geocoder->geocode(PlaceIdQuery::make('ChIJ-abc'));

    $this->travel(29)->days();
    $result = $geocoder->geocode(PlaceIdQuery::make('ChIJ-abc'));

    expect($adapter->calls())->toBe(2)
        ->and($result->latitude)->toBe(3.0);
    $this->assertDatabaseCount('geocode_cache', 1);
});

it('returns null and stores nothing when the place is unknown', function () {
    $adapter = new FakePlaceAdapter([null]);

    expect((new CachedGeocoder($adapter))->geocode(PlaceIdQuery::make('ChIJ-gone')))->toBeNull();
    $this->assertDatabaseCount('geocode_cache', 0);
});

it('keeps place ID entries and address entries apart', function () {
    $places = new FakePlaceAdapter([new Coordinates(1.0, 2.0, 'ChIJ-abc')]);
    (new CachedGeocoder($places))->geocode(PlaceIdQuery::make('ChIJ-abc'));
    $addresses = FakeAdapter::returning(3.0, 4.0, 'ChIJ-abc');
    (new CachedGeocoder($addresses))->geocode('ChIJ-abc');

    expect($addresses->calls())->toBe(1);
    $this->assertDatabaseCount('geocode_cache', 2);
});

it('keeps the case of the place ID in the cache key', function () {
    $adapter = new FakePlaceAdapter([new Coordinates(1.0, 2.0), new Coordinates(3.0, 4.0)]);
    $geocoder = new CachedGeocoder($adapter);
    $geocoder->geocode(PlaceIdQuery::make('ChIJ-abc'));
    $geocoder->geocode(PlaceIdQuery::make('ChIJ-ABC'));

    expect($adapter->calls())->toBe(2);
});

it('refuses a place ID before it reads the cache when the adapter cannot look it up', function () {
    $places = new FakePlaceAdapter([new Coordinates(1.0, 2.0, 'ChIJ-abc')]);
    (new CachedGeocoder($places))->geocode(PlaceIdQuery::make('ChIJ-abc'));

    $adapter = new FakeAdapter;
    $geocoder = new CachedGeocoder($adapter);

    expect(fn () => $geocoder->geocode(PlaceIdQuery::make('ChIJ-abc')))->toThrow(LogicException::class, 'place ID')
        ->and($adapter->calls())->toBe(0)
        ->and($geocoder->supports(PlaceIdQuery::make('ChIJ-abc')))->toBeFalse()
        ->and($geocoder->supports(GeocodeQuery::make('Berlin')))->toBeTrue();
    $this->assertDatabaseCount('geocode_cache', 1);
});

it('refuses a place ID for a driver that cannot look it up', function () {
    GeocodeCache::extend('plain', fn () => new FakeAdapter);

    expect(fn () => GeocodeCache::driver('plain')->geocode(PlaceIdQuery::make('ChIJ-abc')))->toThrow(LogicException::class);
});

it('looks a place ID up through the facade', function () {
    $adapter = new FakePlaceAdapter([new Coordinates(1.0, 2.0, 'ChIJ-abc')]);
    GeocodeCache::extend('places', fn () => $adapter);
    config(['geocode-cache.driver' => 'places']);

    expect(GeocodeCache::geocode(PlaceIdQuery::make('ChIJ-abc'))->latitude)->toBe(1.0);
});

it('lets the application fall back to the address when the place is gone', function () {
    $places = new FakePlaceAdapter([null]);
    $result = (new CachedGeocoder($places))->geocode(PlaceIdQuery::make('ChIJ-gone'))
        ?? (new CachedGeocoder(FakeAdapter::returning(5.0, 6.0, 'ChIJ-new')))->geocode('Berlin');

    expect($result->placeId)->toBe('ChIJ-new');
});

it('stores the place ID query encrypted for the refresh', function () {
    config(['geocode-cache.refresh.enabled' => true, 'geocode-cache.refresh.store_query' => true]);
    (new CachedGeocoder(new FakePlaceAdapter([new Coordinates(1.0, 2.0, 'ChIJ-abc')])))->geocode(PlaceIdQuery::make('ChIJ-secret'));

    expect((string) DB::table('geocode_cache')->value('query_payload'))->not->toContain('ChIJ-secret')
        ->and(GeocodeCacheEntry::query()->firstOrFail()->query_payload)->toBe(['type' => 'place_id', 'place_id' => 'ChIJ-secret']);
});

it('refreshes a place ID entry that is in use and expires soon', function () {
    config(['geocode-cache.refresh.enabled' => true, 'geocode-cache.refresh.store_query' => true, 'geocode-cache.refresh.requested_within_days' => 30, 'geocode-cache.driver' => 'places']);
    $adapter = new FakePlaceAdapter([new Coordinates(1.0, 2.0, 'ChIJ-abc')]);
    GeocodeCache::extend('places', fn () => $adapter);
    GeocodeCache::geocode(PlaceIdQuery::make('ChIJ-abc'));

    $this->travel(27)->days();
    $adapter->queue(new Coordinates(3.0, 4.0, 'ChIJ-abc'));

    $this->artisan('geocode-cache:refresh')
        ->expectsOutputToContain('1 refreshed')
        ->assertSuccessful();

    $entry = GeocodeCacheEntry::query()->firstOrFail();
    expect($adapter->calls())->toBe(2)
        ->and($adapter->placeQueries[1]->placeId)->toBe('ChIJ-abc')
        ->and($entry->latitude)->toBe(3.0)
        ->and($entry->expires_at->equalTo(now()->addDays(29)))->toBeTrue();
});

it('skips the place ID entries when the refresh runs with a driver that cannot look them up', function () {
    config(['geocode-cache.refresh.enabled' => true, 'geocode-cache.refresh.store_query' => true, 'geocode-cache.refresh.requested_within_days' => 30, 'geocode-cache.driver' => 'places']);
    $places = new FakePlaceAdapter([new Coordinates(1.0, 2.0, 'ChIJ-abc')]);
    GeocodeCache::extend('places', fn () => $places);
    GeocodeCache::geocode(PlaceIdQuery::make('ChIJ-abc'));
    $addresses = new FakeAdapter;
    GeocodeCache::extend('addresses', fn () => $addresses);

    $this->travel(27)->days();

    $this->artisan('geocode-cache:refresh', ['--driver' => 'addresses'])
        ->expectsOutputToContain('1 skipped')
        ->assertSuccessful();

    expect($addresses->calls())->toBe(0)
        ->and(GeocodeCacheEntry::query()->firstOrFail()->latitude)->toBe(1.0);
});

it('still reads the address entries that were stored before place IDs existed', function () {
    config(['geocode-cache.refresh.enabled' => true, 'geocode-cache.refresh.store_query' => true, 'geocode-cache.refresh.requested_within_days' => 30, 'geocode-cache.driver' => 'addresses']);
    $adapter = new FakeAdapter([new Coordinates(1.0, 2.0, 'p')]);
    GeocodeCache::extend('addresses', fn () => $adapter);
    GeocodeCache::geocode('Berlin');
    $adapter->queries = [];
    $adapter->queue(new Coordinates(3.0, 4.0, 'p'));

    // The payload of an address query has no type, like the ones stored by earlier versions.
    expect(GeocodeCacheEntry::query()->firstOrFail()->query_payload)->not->toHaveKey('type');

    $this->travel(27)->days();
    $this->artisan('geocode-cache:refresh')->expectsOutputToContain('1 refreshed')->assertSuccessful();
});

it('refuses to refresh a place ID for an adapter that cannot look it up', function () {
    $geocoder = new CachedGeocoder(new FakeAdapter);

    expect(fn () => $geocoder->refresh(PlaceIdQuery::make('ChIJ-abc')))->toThrow(LogicException::class);
});

it('leaves a place ID entry as it is when the refresh finds nothing', function () {
    config(['geocode-cache.refresh.enabled' => true, 'geocode-cache.refresh.store_query' => true, 'geocode-cache.refresh.requested_within_days' => 30, 'geocode-cache.driver' => 'places']);
    $adapter = new FakePlaceAdapter([new Coordinates(1.0, 2.0, 'ChIJ-abc')]);
    GeocodeCache::extend('places', fn () => $adapter);
    GeocodeCache::geocode(PlaceIdQuery::make('ChIJ-abc'));
    $expiresAt = GeocodeCacheEntry::query()->firstOrFail()->expires_at;

    $this->travel(27)->days();
    $adapter->queue(null);

    $this->artisan('geocode-cache:refresh')->expectsOutputToContain('1 without result')->assertSuccessful();

    $entry = GeocodeCacheEntry::query()->firstOrFail();
    expect($entry->latitude)->toBe(1.0)
        ->and($entry->expires_at->equalTo($expiresAt))->toBeTrue();
});

it('renews an address entry and a place ID entry of the same place with one call', function () {
    config(['geocode-cache.refresh.enabled' => true, 'geocode-cache.refresh.store_query' => true, 'geocode-cache.refresh.requested_within_days' => 30, 'geocode-cache.driver' => 'places']);
    $adapter = new FakePlaceAdapter([new Coordinates(1.0, 2.0, 'ChIJ-shared'), new Coordinates(1.0, 2.0, 'ChIJ-shared')]);
    GeocodeCache::extend('places', fn () => $adapter);
    GeocodeCache::geocode('Berlin');
    GeocodeCache::geocode(PlaceIdQuery::make('ChIJ-shared'));
    $calls = $adapter->calls();

    $this->travel(27)->days();
    $adapter->queue(new Coordinates(3.0, 4.0, 'ChIJ-shared'));

    $this->artisan('geocode-cache:refresh')
        ->expectsOutputToContain('1 refreshed, 1 copied to entries of the same place')
        ->assertSuccessful();

    expect($adapter->calls() - $calls)->toBe(1)
        ->and(GeocodeCacheEntry::query()->pluck('latitude')->all())->toBe([3.0, 3.0]);
});
