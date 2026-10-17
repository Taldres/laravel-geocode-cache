<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Taldres\GeocodeCache\Data\Coordinates;
use Taldres\GeocodeCache\Data\PlaceIdQuery;
use Taldres\GeocodeCache\Enums\RefreshOutcome;
use Taldres\GeocodeCache\Exceptions\GeocodingFailedException;
use Taldres\GeocodeCache\Facades\GeocodeCache;
use Taldres\GeocodeCache\Models\GeocodeCacheEntry;
use Taldres\GeocodeCache\Tests\Fixtures\FakeAdapter;
use Taldres\GeocodeCache\Tests\Fixtures\FakePlaceAdapter;

beforeEach(function () {
    $this->travelTo('2026-10-17 12:00:00');
    config([
        'geocode-cache.refresh.enabled' => true,
        'geocode-cache.refresh.store_query' => false,
        'geocode-cache.refresh.requested_within_days' => 30,
        'geocode-cache.driver' => 'places',
    ]);
});

function placeAdapter(Coordinates ...$responses): FakePlaceAdapter
{
    $adapter = new FakePlaceAdapter(array_values($responses));
    GeocodeCache::extend('places', fn () => $adapter);

    return $adapter;
}

it('stores no query without the setting, also none for a place ID query', function () {
    $adapter = placeAdapter(new Coordinates(1.0, 2.0, 'p1'), new Coordinates(1.0, 2.0, 'p2'));
    GeocodeCache::geocode('Secret Street 1, Berlin');
    GeocodeCache::geocode(PlaceIdQuery::make('ChIJ-secret'));

    expect(DB::table('geocode_cache')->whereNotNull('query_payload')->count())->toBe(0)
        ->and(json_encode(DB::table('geocode_cache')->get()))->not->toContain('ecret')
        ->and($adapter->calls())->toBe(2);
});

it('refreshes an entry without a stored query through its place ID', function () {
    $adapter = placeAdapter(new Coordinates(1.0, 2.0, 'p1'));
    GeocodeCache::geocode('Berlin');
    $hash = GeocodeCacheEntry::query()->firstOrFail()->query_hash;
    $lastRequestedAt = GeocodeCacheEntry::query()->firstOrFail()->last_requested_at;

    $this->travel(27)->days();
    $adapter->queue(new Coordinates(3.0, 4.0, 'p1'));

    $this->artisan('geocode-cache:refresh')
        ->expectsOutputToContain('1 refreshed, 0 copied to entries of the same place, 0 without result, 0 skipped, 0 failed.')
        ->assertSuccessful();

    $entry = GeocodeCacheEntry::query()->firstOrFail();
    expect($adapter->placeQueries)->toHaveCount(1)
        ->and($adapter->placeQueries[0]->placeId)->toBe('p1')
        ->and($adapter->queries)->toHaveCount(1)
        ->and($entry->query_hash)->toBe($hash)
        ->and($entry->query_payload)->toBeNull()
        ->and($entry->latitude)->toBe(3.0)
        ->and($entry->expires_at->equalTo(now()->addDays(29)))->toBeTrue()
        ->and($entry->last_requested_at->equalTo($lastRequestedAt))->toBeTrue();
});

it('renews the entries of one place with a single call', function () {
    $adapter = placeAdapter(new Coordinates(1.0, 2.0, 'p1'), new Coordinates(1.0, 2.0, 'p1'));
    GeocodeCache::geocode('Berlin');
    GeocodeCache::geocode('Berlin Mitte');

    $this->travel(27)->days();
    $adapter->queue(new Coordinates(3.0, 4.0, 'p1'));

    $this->artisan('geocode-cache:refresh')->expectsOutputToContain('1 refreshed, 1 copied')->assertSuccessful();

    expect($adapter->placeQueries)->toHaveCount(1)
        ->and(GeocodeCacheEntry::query()->pluck('latitude')->all())->toBe([3.0, 3.0]);
});

it('prefers the stored query to the place ID and keeps it', function () {
    config(['geocode-cache.refresh.store_query' => true]);
    $adapter = placeAdapter(new Coordinates(1.0, 2.0, 'p1'));
    GeocodeCache::geocode('Berlin');
    $payload = GeocodeCacheEntry::query()->firstOrFail()->query_payload;

    $this->travel(27)->days();
    $adapter->queue(new Coordinates(3.0, 4.0, 'p1'));

    $this->artisan('geocode-cache:refresh')->expectsOutputToContain('1 refreshed')->assertSuccessful();

    expect($adapter->queries)->toHaveCount(2)
        ->and($adapter->placeQueries)->toHaveCount(0)
        ->and(GeocodeCacheEntry::query()->firstOrFail()->query_payload)->toBe($payload);
});

it('never replaces the stored query when it renews an entry through another lookup', function () {
    config(['geocode-cache.refresh.store_query' => true]);
    $adapter = placeAdapter(new Coordinates(1.0, 2.0, 'p1'));
    GeocodeCache::geocode('Berlin');
    $entry = GeocodeCacheEntry::query()->firstOrFail();
    $adapter->queue(new Coordinates(3.0, 4.0, 'p1'));

    $outcome = GeocodeCache::driver('places')->refreshUsing($entry->query_hash, PlaceIdQuery::make('p1'), $entry->fetched_at);

    expect($outcome)->toBe(RefreshOutcome::Refreshed)
        ->and(GeocodeCacheEntry::query()->firstOrFail()->query_payload)->toBe($entry->query_payload)
        ->and(GeocodeCacheEntry::query()->firstOrFail()->latitude)->toBe(3.0);
});

it('does not renew an entry that was renewed meanwhile, or does not exist', function () {
    $adapter = placeAdapter(new Coordinates(1.0, 2.0, 'p1'));
    GeocodeCache::geocode('Berlin');
    $entry = GeocodeCacheEntry::query()->firstOrFail();
    $geocoder = GeocodeCache::driver('places');

    $this->travel(1)->minutes();

    expect($geocoder->refreshUsing($entry->query_hash, PlaceIdQuery::make('p1'), now()->subDay()))->toBe(RefreshOutcome::Skipped)
        ->and($geocoder->refreshUsing(str_repeat('0', 64), PlaceIdQuery::make('p1')))->toBe(RefreshOutcome::Skipped)
        ->and($adapter->placeQueries)->toHaveCount(0);
});

it('leaves the entry alone when the place is not valid anymore and heals on the next request', function () {
    $adapter = placeAdapter(new Coordinates(1.0, 2.0, 'p1'));
    GeocodeCache::geocode('Berlin');
    $expiresAt = GeocodeCacheEntry::query()->firstOrFail()->expires_at;

    $this->travel(27)->days();
    $adapter->queue(null);

    $this->artisan('geocode-cache:refresh')->expectsOutputToContain('1 without result')->assertSuccessful();

    $entry = GeocodeCacheEntry::query()->firstOrFail();
    expect($entry->latitude)->toBe(1.0)
        ->and($entry->expires_at->equalTo($expiresAt))->toBeTrue();

    // After the entry expired, the next request asks for the address again and gets the new place ID.
    $this->travel(3)->days();
    $adapter->queue(new Coordinates(5.0, 6.0, 'p2'));
    $result = GeocodeCache::geocode('Berlin');

    expect($result->placeId)->toBe('p2')
        ->and(GeocodeCacheEntry::query()->firstOrFail()->place_id)->toBe('p2');
});

it('counts a failed place ID lookup as a failure and changes nothing', function () {
    $adapter = placeAdapter(new Coordinates(1.0, 2.0, 'p1'));
    GeocodeCache::geocode('Berlin');

    $this->travel(27)->days();
    $adapter->queue(fn () => throw GeocodingFailedException::connection());

    $this->artisan('geocode-cache:refresh')->expectsOutputToContain('1 failed')->assertFailed();

    expect(GeocodeCacheEntry::query()->firstOrFail()->latitude)->toBe(1.0);
});

it('skips the entries without a query when the adapter cannot look a place ID up', function () {
    placeAdapter(new Coordinates(1.0, 2.0, 'p1'));
    GeocodeCache::geocode('Berlin');
    $plain = new FakeAdapter;
    GeocodeCache::extend('plain', fn () => $plain);

    // The entry was stored without a query. Storing queries now does not give it one.
    config(['geocode-cache.refresh.store_query' => true]);
    $this->travel(27)->days();

    $this->artisan('geocode-cache:refresh', ['--driver' => 'plain'])->expectsOutputToContain('1 skipped')->assertSuccessful();

    expect($plain->calls())->toBe(0);
});

it('skips an entry whose stored place ID is not a place ID', function () {
    placeAdapter(new Coordinates(1.0, 2.0, 'p1'));
    GeocodeCache::geocode('Berlin');
    DB::table('geocode_cache')->update(['place_id' => 'not a place id']);

    $this->travel(27)->days();

    $this->artisan('geocode-cache:refresh')->expectsOutputToContain('1 skipped')->assertSuccessful();
});

it('does not consider an entry that has neither a query nor a place ID', function () {
    config(['geocode-cache.store_place_id' => false, 'geocode-cache.refresh.store_query' => true]);
    $adapter = placeAdapter(new Coordinates(1.0, 2.0, 'p1'));
    GeocodeCache::geocode('Berlin');
    DB::table('geocode_cache')->update(['query_payload' => null]);

    $this->travel(27)->days();

    $this->artisan('geocode-cache:refresh')->expectsOutputToContain('0 refreshed')->assertSuccessful();

    expect($adapter->calls())->toBe(1);
});

it('refuses to run when nothing is stored to refresh with', function () {
    config(['geocode-cache.store_place_id' => false, 'geocode-cache.refresh.store_query' => false]);

    $this->artisan('geocode-cache:refresh')->expectsOutputToContain('Nothing is stored to refresh with')->assertFailed();
});

it('shows in the about command when nothing is stored to refresh with', function () {
    config(['geocode-cache.store_place_id' => false, 'geocode-cache.refresh.store_query' => false]);

    $this->artisan('about', ['--only' => 'geocode_cache'])->expectsOutputToContain('NOTHING TO REFRESH WITH')->assertSuccessful();
});

it('renews with the retention of the source', function () {
    config(['geocode-cache.source_ttl_days' => ['google' => 10], 'geocode-cache.refresh.expiring_within_days' => 3]);
    $adapter = placeAdapter(new Coordinates(1.0, 2.0, 'p1'));
    GeocodeCache::geocode('Berlin');
    $this->travel(8)->days();
    $adapter->queue(new Coordinates(3.0, 4.0, 'p1'));

    $this->artisan('geocode-cache:refresh')->assertSuccessful();

    expect(GeocodeCacheEntry::query()->firstOrFail()->expires_at->equalTo(now()->addDays(10)))->toBeTrue();
});

it('ignores and purges a stored query when the setting is switched off', function () {
    config(['geocode-cache.refresh.store_query' => true]);
    $adapter = placeAdapter(new Coordinates(1.0, 2.0, 'p1'));
    GeocodeCache::geocode('Berlin');
    expect(DB::table('geocode_cache')->whereNotNull('query_payload')->count())->toBe(1);

    config(['geocode-cache.refresh.store_query' => false]);
    $this->travel(27)->days();
    $adapter->queue(new Coordinates(3.0, 4.0, 'p1'));

    $this->artisan('geocode-cache:refresh')->expectsOutputToContain('1 refreshed')->assertSuccessful();

    expect($adapter->placeQueries)->toHaveCount(1)
        ->and($adapter->queries)->toHaveCount(1)
        ->and(DB::table('geocode_cache')->whereNotNull('query_payload')->count())->toBe(0);
});

it('does not consider an entry that only has an old stored query when the setting is off', function () {
    config(['geocode-cache.store_place_id' => false, 'geocode-cache.refresh.store_query' => true]);
    $adapter = placeAdapter(new Coordinates(1.0, 2.0));
    GeocodeCache::geocode('Berlin');

    config(['geocode-cache.store_place_id' => true, 'geocode-cache.refresh.store_query' => false]);
    $this->travel(27)->days();

    $this->artisan('geocode-cache:refresh')->expectsOutputToContain('0 refreshed')->assertSuccessful();

    expect($adapter->calls())->toBe(1);
});

it('uses the stored query of a unit if one entry has it', function () {
    config(['geocode-cache.refresh.store_query' => true]);
    $adapter = placeAdapter(new Coordinates(1.0, 2.0, 'p1'), new Coordinates(1.0, 2.0, 'p1'));
    GeocodeCache::geocode('Berlin');
    GeocodeCache::geocode('Berlin Mitte');
    DB::table('geocode_cache')->where('id', 1)->update(['query_payload' => null]);

    $this->travel(27)->days();
    $adapter->queue(new Coordinates(3.0, 4.0, 'p1'));

    $this->artisan('geocode-cache:refresh')->expectsOutputToContain('1 refreshed, 1 copied')->assertSuccessful();

    expect($adapter->placeQueries)->toHaveCount(0)
        ->and($adapter->queries)->toHaveCount(3)
        ->and(GeocodeCacheEntry::query()->pluck('latitude')->all())->toBe([3.0, 3.0]);
});

it('does not copy to entries of another place when Google returns another place ID', function () {
    $adapter = placeAdapter(new Coordinates(1.0, 2.0, 'p1'), new Coordinates(1.0, 2.0, 'p1'));
    GeocodeCache::geocode('Berlin');
    GeocodeCache::geocode('Berlin Mitte');

    $this->travel(27)->days();
    $adapter->queue(new Coordinates(3.0, 4.0, 'p9'));

    $this->artisan('geocode-cache:refresh')->expectsOutputToContain('1 refreshed, 0 copied')->assertSuccessful();

    expect(GeocodeCacheEntry::query()->orderBy('id')->pluck('place_id')->all())->toBe(['p9', 'p1']);
});

it('refuses to run when the adapter cannot look place IDs up and no query is stored', function () {
    GeocodeCache::extend('plain', fn () => new FakeAdapter);
    config(['geocode-cache.driver' => 'plain']);

    $this->artisan('geocode-cache:refresh')->expectsOutputToContain('cannot look place IDs up')->assertFailed();
});

it('shows in the about command that the adapter cannot look place IDs up', function () {
    GeocodeCache::extend('plain', fn () => new FakeAdapter);
    config(['geocode-cache.driver' => 'plain']);

    $this->artisan('about', ['--only' => 'geocode_cache'])->expectsOutputToContain('CANNOT LOOK PLACE IDS UP')->assertSuccessful();
});

it('reports with --pretend only the calls the refresh would make', function () {
    placeAdapter(new Coordinates(1.0, 2.0, 'p1'), new Coordinates(1.0, 2.0, 'p1'), new Coordinates(1.0, 2.0, 'p2'));
    GeocodeCache::geocode('Berlin');
    GeocodeCache::geocode('Berlin Mitte');
    GeocodeCache::geocode('Hamburg');
    DB::table('geocode_cache')->where('query_hash', GeocodeCacheEntry::query()->orderByDesc('id')->firstOrFail()->query_hash)->update(['place_id' => 'not a place id']);

    $this->travel(27)->days();

    $this->artisan('geocode-cache:refresh --pretend')
        ->expectsOutputToContain('1 service calls would refresh up to 2 geocode cache entries (limit 100).')
        ->assertSuccessful();
});
