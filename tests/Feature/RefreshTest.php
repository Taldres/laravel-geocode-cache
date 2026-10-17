<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use Taldres\GeocodeCache\Data\Coordinates;
use Taldres\GeocodeCache\Data\GeocodeQuery;
use Taldres\GeocodeCache\Enums\RefreshOutcome;
use Taldres\GeocodeCache\Exceptions\GeocodingFailedException;
use Taldres\GeocodeCache\Facades\GeocodeCache;
use Taldres\GeocodeCache\Models\GeocodeCacheEntry;
use Taldres\GeocodeCache\Tests\Fixtures\FakeAdapter;

beforeEach(function () {
    $this->travelTo('2026-10-17 12:00:00');
    config([
        'geocode-cache.refresh.enabled' => true, 'geocode-cache.refresh.store_query' => true,
        'geocode-cache.refresh.requested_within_days' => 30,
        'geocode-cache.driver' => 'fake',
    ]);
});

function seedEntry(string ...$searches): FakeAdapter
{
    $adapter = new FakeAdapter;
    GeocodeCache::extend('fake', fn () => $adapter);

    foreach ($searches ?: ['Berlin'] as $search) {
        $adapter->queue(new Coordinates(1.0, 2.0, 'place-'.Str::slug($search)));
        GeocodeCache::geocode($search);
    }

    $adapter->queries = [];

    return $adapter;
}

it('stores the search text encrypted when the refresh is enabled', function () {
    seedEntry('Secret Street 1, Berlin');

    expect((string) DB::table('geocode_cache')->value('query_payload'))->not->toContain('secret')
        ->and(GeocodeCacheEntry::query()->firstOrFail()->query_payload)->toMatchArray(['search' => 'secret street 1, berlin']);
});

it('stores no search text when the refresh is disabled', function () {
    config(['geocode-cache.refresh.enabled' => false]);
    seedEntry('Secret Street 1, Berlin');

    expect(DB::table('geocode_cache')->value('query_payload'))->toBeNull();
});

it('records a request at most once per interval and never changes the expiry', function () {
    seedEntry();
    $expiresAt = GeocodeCacheEntry::query()->firstOrFail()->expires_at;

    $this->travel(30)->minutes();
    GeocodeCache::geocode('Berlin');
    expect(GeocodeCacheEntry::query()->firstOrFail()->last_requested_at->equalTo(now()->subMinutes(30)))->toBeTrue();

    $this->travel(31)->minutes();
    GeocodeCache::geocode('Berlin');

    $entry = GeocodeCacheEntry::query()->firstOrFail();
    expect($entry->last_requested_at->equalTo(now()))->toBeTrue()
        ->and($entry->expires_at->equalTo($expiresAt))->toBeTrue();
});

it('refreshes an entry that is in use and expires soon', function () {
    $adapter = seedEntry();
    $this->travel(27)->days();
    $adapter->queue(new Coordinates(3.0, 4.0, 'new'));

    $this->artisan('geocode-cache:refresh')
        ->expectsOutputToContain('1 refreshed, 0 copied to entries of the same place, 0 without result, 0 skipped, 0 failed.')
        ->assertSuccessful();

    $entry = GeocodeCacheEntry::query()->firstOrFail();

    expect($adapter->calls())->toBe(1)
        ->and($entry->latitude)->toBe(3.0)
        ->and($entry->place_id)->toBe('new')
        ->and($entry->fetched_at->equalTo(now()))->toBeTrue()
        ->and($entry->expires_at->equalTo(now()->addDays(29)))->toBeTrue()
        // A refresh is not a request, so an unused entry does not stay alive on its own.
        ->and($entry->last_requested_at->equalTo(now()->subDays(27)))->toBeTrue();
});

it('refreshes an entry that was requested again after it was stored', function () {
    config(['geocode-cache.refresh.requested_within_days' => 14]);
    $adapter = seedEntry();
    $this->travel(20)->days();
    GeocodeCache::geocode('Berlin');
    $this->travel(7)->days();
    $adapter->queue(new Coordinates(3.0, 4.0));

    $this->artisan('geocode-cache:refresh')->assertSuccessful();

    expect(GeocodeCacheEntry::query()->firstOrFail()->latitude)->toBe(3.0);
});

it('does not refresh an entry that has not been requested for a long time', function () {
    $adapter = seedEntry();
    config(['geocode-cache.refresh.requested_within_days' => 14, 'geocode-cache.refresh.expiring_within_days' => 20]);
    $this->travel(15)->days();

    $this->artisan('geocode-cache:refresh')
        ->expectsOutputToContain('0 refreshed')
        ->assertSuccessful();

    expect($adapter->calls())->toBe(0)
        ->and(GeocodeCacheEntry::query()->firstOrFail()->latitude)->toBe(1.0);
});

it('does not refresh an entry that does not expire soon', function () {
    $adapter = seedEntry();
    $this->travel(5)->days();

    $this->artisan('geocode-cache:refresh')->assertSuccessful();

    expect($adapter->calls())->toBe(0);
});

it('does not refresh an expired entry', function () {
    $adapter = seedEntry();
    $this->travel(29)->days();

    $this->artisan('geocode-cache:refresh')->assertSuccessful();

    expect($adapter->calls())->toBe(0)
        ->and(GeocodeCacheEntry::query()->firstOrFail()->latitude)->toBe(1.0);
});

it('does not extend an entry when the refresh fails', function () {
    $adapter = seedEntry();
    $this->travel(27)->days();
    $before = GeocodeCacheEntry::query()->firstOrFail()->getAttributes();
    $adapter->queue(fn () => throw GeocodingFailedException::connection());

    $this->artisan('geocode-cache:refresh')
        ->expectsOutputToContain('0 refreshed, 0 copied to entries of the same place, 0 without result, 0 skipped, 1 failed.')
        ->assertFailed();

    expect(GeocodeCacheEntry::query()->firstOrFail()->getAttributes())->toBe($before);
});

it('keeps the old entry when the service finds nothing anymore', function () {
    $adapter = seedEntry();
    $this->travel(27)->days();
    $adapter->queue(null);

    $this->artisan('geocode-cache:refresh')
        ->expectsOutputToContain('0 refreshed, 0 copied to entries of the same place, 1 without result')
        ->assertSuccessful();

    expect(GeocodeCacheEntry::query()->firstOrFail()->latitude)->toBe(1.0);
});

it('stops after repeated failures', function () {
    $adapter = seedEntry('a', 'b', 'c', 'd', 'e', 'f', 'g');
    $this->travel(27)->days();

    foreach (range(1, 7) as $ignored) {
        $adapter->queue(fn () => throw GeocodingFailedException::serviceStatus('REQUEST_DENIED'));
    }

    $this->artisan('geocode-cache:refresh')
        ->expectsOutputToContain('Stopped after 5 failures in a row.')
        ->assertFailed();

    expect($adapter->calls())->toBe(5);
});

it('caps the service calls of a run', function () {
    $adapter = seedEntry('a', 'b', 'c');
    $this->travel(27)->days();
    $adapter->queue(new Coordinates(3.0, 4.0))->queue(new Coordinates(3.0, 4.0))->queue(new Coordinates(3.0, 4.0));

    $this->artisan('geocode-cache:refresh --limit=2')
        ->expectsOutputToContain('2 refreshed')
        ->assertSuccessful();

    expect($adapter->calls())->toBe(2);
});

it('skips an entry whose search text can no longer be decrypted', function () {
    $adapter = seedEntry();
    DB::table('geocode_cache')->update(['query_payload' => 'not-encrypted']);
    $this->travel(27)->days();

    $this->artisan('geocode-cache:refresh')
        ->expectsOutputToContain('0 refreshed, 0 copied to entries of the same place, 0 without result, 1 skipped, 0 failed.')
        ->assertSuccessful();

    expect($adapter->calls())->toBe(0);
});

it('skips an entry whose hash does not match its search text', function () {
    $adapter = seedEntry();
    DB::table('geocode_cache')->update(['query_hash' => hash('sha256', 'something else')]);
    $this->travel(27)->days();

    $this->artisan('geocode-cache:refresh')
        ->expectsOutputToContain('1 skipped')
        ->assertSuccessful();

    expect($adapter->calls())->toBe(0);
});

it('only refreshes entries of the source of the driver', function () {
    $adapter = seedEntry();
    DB::table('geocode_cache')->update(['source' => 'other']);
    $this->travel(27)->days();

    $this->artisan('geocode-cache:refresh')->assertSuccessful();

    expect($adapter->calls())->toBe(0);
});

it('refreshes nothing with --pretend', function () {
    $adapter = seedEntry();
    $this->travel(27)->days();

    $this->artisan('geocode-cache:refresh --pretend')
        ->expectsOutputToContain('1 service calls would refresh up to 1 geocode cache entries (limit 100).')
        ->assertSuccessful();

    expect($adapter->calls())->toBe(0);
});

it('refuses to run while the refresh is disabled', function () {
    config(['geocode-cache.refresh.enabled' => false]);

    $this->artisan('geocode-cache:refresh')
        ->expectsOutputToContain('The refresh is disabled.')
        ->assertFailed();
});

it('refuses an invalid limit', function (string $limit) {
    $this->artisan("geocode-cache:refresh --limit={$limit}")
        ->expectsOutputToContain('The limit must be a whole number of 1 or more.')
        ->assertFailed();
})->with(['0', '-3', 'many', '1.5']);

it('refuses an unknown driver', function () {
    $this->artisan('geocode-cache:refresh --driver=nope')
        ->expectsOutputToContain('Driver [nope] not supported.')
        ->assertFailed();
});

it('does not bring back an entry that was deleted after the run selected it', function () {
    $adapter = seedEntry();
    $entry = GeocodeCacheEntry::query()->firstOrFail();
    $this->travel(27)->days();
    GeocodeCacheEntry::query()->delete();

    $outcome = GeocodeCache::driver('fake')->refresh(GeocodeQuery::fromArray($entry->query_payload ?? []), $entry->fetched_at);

    expect($outcome)->toBe(RefreshOutcome::Skipped)
        ->and($adapter->calls())->toBe(0);
    $this->assertDatabaseCount('geocode_cache', 0);
});

it('does not bring back an entry that was deleted while the service was called', function () {
    $adapter = seedEntry();
    $query = GeocodeQuery::make('Berlin');
    $this->travel(27)->days();
    $adapter->queue(function () {
        GeocodeCacheEntry::query()->delete();

        return new Coordinates(3.0, 4.0);
    });

    expect(GeocodeCache::driver('fake')->refresh($query))->toBe(RefreshOutcome::Skipped);
    $this->assertDatabaseCount('geocode_cache', 0);
});

it('does not ask the service again for an entry that was renewed since the run selected it', function () {
    $adapter = seedEntry();
    $selected = GeocodeCacheEntry::query()->firstOrFail()->fetched_at;
    $this->travel(27)->days();
    $adapter->queue(new Coordinates(3.0, 4.0));
    GeocodeCache::driver('fake')->refresh(GeocodeQuery::make('Berlin'));

    $outcome = GeocodeCache::driver('fake')->refresh(GeocodeQuery::make('Berlin'), $selected);

    expect($outcome)->toBe(RefreshOutcome::Skipped)
        ->and($adapter->calls())->toBe(1);
});

it('does not refresh an entry that expired after the run selected it', function () {
    $adapter = seedEntry();
    $this->travel(29)->days();

    expect(GeocodeCache::driver('fake')->refresh(GeocodeQuery::make('Berlin')))->toBe(RefreshOutcome::Skipped)
        ->and($adapter->calls())->toBe(0);
    $this->assertDatabaseCount('geocode_cache', 0);
});

it('fails instead of counting a request when the lock stays taken during a refresh', function () {
    config(['geocode-cache.lock.wait' => 1]);
    Sleep::fake(syncWithCarbon: true);
    $adapter = seedEntry();
    $this->travel(27)->days();
    Cache::lock('geocode-cache:'.GeocodeQuery::make('Berlin')->hash('google'), 60)->get();
    $requestedAt = GeocodeCacheEntry::query()->firstOrFail()->last_requested_at;

    expect(fn () => GeocodeCache::driver('fake')->refresh(GeocodeQuery::make('Berlin')))
        ->toThrow(GeocodingFailedException::class, 'Another process')
        ->and($adapter->calls())->toBe(0)
        ->and(GeocodeCacheEntry::query()->firstOrFail()->last_requested_at->equalTo($requestedAt))->toBeTrue();
});

it('skips an entry whose search text is not a query', function () {
    $adapter = seedEntry();
    DB::table('geocode_cache')->update(['query_payload' => Crypt::encryptString('5')]);
    $this->travel(27)->days();

    $this->artisan('geocode-cache:refresh')
        ->expectsOutputToContain('1 skipped')
        ->assertSuccessful();

    expect($adapter->calls())->toBe(0);
});

/**
 * Looks up the search texts, which all resolve to the same place, so they are stored as separate entries.
 */
function seedEntriesOfOnePlace(string $placeId, string ...$searches): FakeAdapter
{
    $adapter = new FakeAdapter;
    GeocodeCache::extend('fake', fn () => $adapter);

    foreach ($searches as $search) {
        $adapter->queue(new Coordinates(1.0, 2.0, $placeId));
        GeocodeCache::geocode($search);
    }

    $adapter->queries = [];

    return $adapter;
}

it('asks the service once for several entries of the same place and renews all of them', function () {
    $adapter = seedEntriesOfOnePlace('shared', 'Alexanderplatz 1, Berlin', 'Alexanderplatz Berlin 10178', 'Berlin Alexanderplatz');
    $requestedAt = GeocodeCacheEntry::query()->orderBy('id')->pluck('last_requested_at', 'id');
    $this->travel(27)->days();
    $adapter->queue(new Coordinates(3.0, 4.0, 'shared'));

    $this->artisan('geocode-cache:refresh')
        ->expectsOutputToContain('1 refreshed, 2 copied to entries of the same place, 0 without result, 0 skipped, 0 failed.')
        ->assertSuccessful();

    $entries = GeocodeCacheEntry::query()->orderBy('id')->get();

    expect($adapter->calls())->toBe(1)
        ->and($entries)->toHaveCount(3)
        ->and($entries->pluck('latitude')->unique()->all())->toBe([3.0])
        ->and($entries->every(fn (GeocodeCacheEntry $entry) => $entry->fetched_at->equalTo(now())))->toBeTrue()
        ->and($entries->every(fn (GeocodeCacheEntry $entry) => $entry->expires_at->equalTo(now()->addDays(29))))->toBeTrue()
        ->and($entries->pluck('last_requested_at', 'id')->map->getTimestamp()->all())->toBe($requestedAt->map->getTimestamp()->all());
});

it('does not renew an entry of the same place that is not in use', function () {
    config(['geocode-cache.refresh.requested_within_days' => 14]);
    $adapter = seedEntriesOfOnePlace('shared', 'Berlin Mitte', 'Berlin Zentrum');
    $this->travel(20)->days();
    GeocodeCache::geocode('Berlin Zentrum');
    $this->travel(7)->days();
    $adapter->queue(new Coordinates(3.0, 4.0, 'shared'));

    $this->artisan('geocode-cache:refresh')
        ->expectsOutputToContain('1 refreshed, 0 copied')
        ->assertSuccessful();

    $unused = GeocodeCacheEntry::query()->where('query_hash', GeocodeQuery::make('Berlin Mitte')->hash('google'))->firstOrFail();
    $used = GeocodeCacheEntry::query()->where('query_hash', GeocodeQuery::make('Berlin Zentrum')->hash('google'))->firstOrFail();

    expect($unused->latitude)->toBe(1.0)
        ->and($used->latitude)->toBe(3.0)
        ->and($adapter->calls())->toBe(1);
});

it('asks the service for every place and for every entry without a place ID', function () {
    $adapter = new FakeAdapter;
    GeocodeCache::extend('fake', fn () => $adapter);

    foreach ([['a', 'place-1'], ['b', 'place-1'], ['c', 'place-2'], ['d', null], ['e', null]] as [$search, $placeId]) {
        $adapter->queue(new Coordinates(1.0, 2.0, $placeId));
        GeocodeCache::geocode($search);
    }

    $adapter->queries = [];
    $this->travel(27)->days();

    foreach (range(1, 4) as $ignored) {
        $adapter->queue(fn (GeocodeQuery $query) => new Coordinates(3.0, 4.0, ['a' => 'place-1', 'b' => 'place-1', 'c' => 'place-2'][$query->search] ?? null));
    }

    $this->artisan('geocode-cache:refresh')
        ->expectsOutputToContain('4 refreshed, 1 copied')
        ->assertSuccessful();

    expect($adapter->calls())->toBe(4);
});

it('counts the limit in service calls and not in entries', function () {
    $adapter = seedEntriesOfOnePlace('shared', 'a', 'b', 'c');
    $this->travel(27)->days();
    $adapter->queue(new Coordinates(3.0, 4.0, 'shared'));

    $this->artisan('geocode-cache:refresh --limit=1')
        ->expectsOutputToContain('1 refreshed, 2 copied')
        ->assertSuccessful();

    expect($adapter->calls())->toBe(1);
});

it('refreshes the places that expire first when the limit is reached', function () {
    $adapter = new FakeAdapter;
    GeocodeCache::extend('fake', fn () => $adapter);
    $adapter->queue(new Coordinates(1.0, 2.0, 'late'));
    GeocodeCache::geocode('late');
    $this->travel(1)->days();
    $adapter->queue(new Coordinates(1.0, 2.0, 'early'));
    GeocodeCache::geocode('early');
    $adapter->queries = [];
    $this->travel(26)->days();
    $adapter->queue(new Coordinates(3.0, 4.0, 'late'));

    $this->artisan('geocode-cache:refresh --limit=1')->assertSuccessful();

    expect($adapter->queries[0]->search)->toBe('late');
});

it('does not touch an entry of the same place that was deleted or renewed during the run', function () {
    $adapter = seedEntriesOfOnePlace('shared', 'a', 'b', 'c');
    $this->travel(27)->days();
    $entries = GeocodeCacheEntry::query()->orderBy('id')->get();
    $adapter->queue(new Coordinates(3.0, 4.0, 'shared'));

    GeocodeCache::driver('fake')->refresh(GeocodeQuery::make('a'));
    GeocodeCacheEntry::query()->whereKey($entries[1]->getKey())->delete();
    GeocodeCacheEntry::query()->whereKey($entries[2]->getKey())->update(['fetched_at' => now()->utc()->subHour()]);

    $updated = GeocodeCache::driver('fake')->shareWithPlace($entries[0]->query_hash, $entries);

    expect($updated)->toBe(0);
    $this->assertDatabaseCount('geocode_cache', 2);
    expect(GeocodeCacheEntry::query()->whereKey($entries[2]->getKey())->firstOrFail()->latitude)->toBe(1.0);
});

it('renews entries of the same place that cannot be decrypted anymore through the readable one', function () {
    $adapter = seedEntriesOfOnePlace('shared', 'a', 'b');
    DB::table('geocode_cache')->where('query_hash', GeocodeQuery::make('a')->hash('google'))->update(['query_payload' => 'not-encrypted']);
    $this->travel(27)->days();
    $adapter->queue(new Coordinates(3.0, 4.0, 'shared'));

    $this->artisan('geocode-cache:refresh')
        ->expectsOutputToContain('1 refreshed, 1 copied')
        ->assertSuccessful();

    expect(GeocodeCacheEntry::query()->pluck('latitude')->unique()->all())->toBe([3.0]);
});

it('skips a whole place when none of its entries can be decrypted', function () {
    $adapter = seedEntriesOfOnePlace('shared', 'a', 'b');
    DB::table('geocode_cache')->update(['query_payload' => 'not-encrypted']);
    $this->travel(27)->days();

    $this->artisan('geocode-cache:refresh')
        ->expectsOutputToContain('0 refreshed, 0 copied to entries of the same place, 0 without result, 2 skipped')
        ->assertSuccessful();

    expect($adapter->calls())->toBe(0);
});

it('copies nothing when the service finds nothing anymore', function () {
    $adapter = seedEntriesOfOnePlace('shared', 'a', 'b');
    $this->travel(27)->days();
    $adapter->queue(null);

    $this->artisan('geocode-cache:refresh')
        ->expectsOutputToContain('0 refreshed, 0 copied to entries of the same place, 1 without result')
        ->assertSuccessful();

    expect(GeocodeCacheEntry::query()->pluck('latitude')->unique()->all())->toBe([1.0]);
});

it('does not copy a result to entries of another place', function () {
    $adapter = seedEntriesOfOnePlace('shared', 'a');
    $this->travel(27)->days();
    $other = GeocodeCacheEntry::query()->firstOrFail();
    $adapter->queue(new Coordinates(3.0, 4.0, 'different'));
    GeocodeCache::driver('fake')->refresh(GeocodeQuery::make('a'));

    expect(GeocodeCache::driver('fake')->shareWithPlace($other->query_hash, [$other]))->toBe(0);
});

it('indexes the place ID', function () {
    expect(collect(Schema::getIndexes('geocode_cache'))->contains(fn (array $index) => $index['columns'] === ['place_id']))->toBeTrue();
});
