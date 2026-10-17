<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Date;
use Taldres\GeocodeCache\CachedGeocoder;
use Taldres\GeocodeCache\Data\Coordinates;
use Taldres\GeocodeCache\Data\GeocodeQuery;
use Taldres\GeocodeCache\Enums\RefreshOutcome;
use Taldres\GeocodeCache\Exceptions\GeocodingFailedException;
use Taldres\GeocodeCache\Models\GeocodeCacheEntry;
use Taldres\GeocodeCache\Tests\Fixtures\FakeAdapter;

beforeEach(function () {
    $this->travelTo('2026-10-17 12:00:00');
});

it('asks the adapter for a query without an entry and stores the coordinates', function () {
    $adapter = FakeAdapter::returning(52.52, 13.405, 'place-1');
    $result = (new CachedGeocoder($adapter))->geocode(GeocodeQuery::make('Alexanderplatz 1, Berlin', 'DE'));

    expect($adapter->calls())->toBe(1)
        ->and($result->latitude)->toBe(52.52)
        ->and($result->longitude)->toBe(13.405)
        ->and($result->placeId)->toBe('place-1')
        ->and($result->source)->toBe('google')
        ->and($result->fetchedAt->equalTo(now()))->toBeTrue()
        ->and($result->expiresAt->equalTo(now()->addDays(29)))->toBeTrue();

    $this->assertDatabaseCount('geocode_cache', 1);
    $this->assertDatabaseHas('geocode_cache', [
        'source' => 'google',
        'latitude' => 52.52,
        'longitude' => 13.405,
        'place_id' => 'place-1',
        'fetched_at' => now()->toDateTimeString(),
        'expires_at' => now()->addDays(29)->toDateTimeString(),
    ]);
});

it('accepts a plain string as the query', function () {
    $adapter = FakeAdapter::returning();
    (new CachedGeocoder($adapter))->geocode('Berlin');

    expect($adapter->queries[0]->search)->toBe('berlin');
});

it('stores nothing but the vetted columns', function () {
    (new CachedGeocoder(FakeAdapter::returning()))->geocode('Berlin');

    expect(array_keys(GeocodeCacheEntry::query()->firstOrFail()->getAttributes()))->toBe([
        'id', 'query_hash', 'source', 'latitude', 'longitude', 'place_id', 'query_payload', 'fetched_at', 'expires_at', 'last_requested_at',
    ])->and(GeocodeCacheEntry::query()->firstOrFail()->query_payload)->toBeNull();
});

it('answers from the cache while the entry is valid', function () {
    $adapter = new FakeAdapter([new Coordinates(52.52, 13.405, 'place-1')]);
    $geocoder = new CachedGeocoder($adapter);
    $first = $geocoder->geocode('Berlin');

    $this->travel(10)->days();
    $second = $geocoder->geocode('Berlin');

    expect($adapter->calls())->toBe(1)
        ->and($second->latitude)->toBe($first->latitude)
        ->and($second->placeId)->toBe('place-1')
        ->and($second->fetchedAt->equalTo($first->fetchedAt))->toBeTrue()
        ->and($second->expiresAt->equalTo($first->expiresAt))->toBeTrue();
});

it('does not extend the period when an entry is read', function () {
    $geocoder = new CachedGeocoder(FakeAdapter::returning());
    $geocoder->geocode('Berlin');
    $expiresAt = GeocodeCacheEntry::query()->firstOrFail()->expires_at;

    $this->travel(20)->days();
    $geocoder->geocode('Berlin');
    $geocoder->geocode('Berlin');

    expect(GeocodeCacheEntry::query()->firstOrFail()->expires_at->equalTo($expiresAt))->toBeTrue();
});

it('uses an entry up to the last second and not a second longer', function () {
    $adapter = new FakeAdapter([new Coordinates(1.0, 2.0), new Coordinates(3.0, 4.0)]);
    $geocoder = new CachedGeocoder($adapter);
    $geocoder->geocode('Berlin');

    $this->travelTo(now()->addDays(29)->subSecond());
    expect($geocoder->geocode('Berlin')->latitude)->toBe(1.0)
        ->and($adapter->calls())->toBe(1);

    $this->travelTo(now()->addSecond());
    expect($geocoder->geocode('Berlin')->latitude)->toBe(3.0)
        ->and($adapter->calls())->toBe(2);
});

it('replaces an expired entry with the new result and a new period', function () {
    $geocoder = new CachedGeocoder(new FakeAdapter([new Coordinates(1.0, 2.0), new Coordinates(3.0, 4.0)]));
    $geocoder->geocode('Berlin');

    $this->travel(30)->days();
    $result = $geocoder->geocode('Berlin');

    expect($result->latitude)->toBe(3.0)
        ->and($result->fetchedAt->equalTo(now()))->toBeTrue()
        ->and($result->expiresAt->equalTo(now()->addDays(29)))->toBeTrue();

    $this->assertDatabaseCount('geocode_cache', 1);
    expect(GeocodeCacheEntry::query()->firstOrFail()->latitude)->toBe(3.0);
});

it('deletes an expired entry when it is accessed, even if the service fails', function () {
    $adapter = new FakeAdapter([
        new Coordinates(1.0, 2.0),
        fn () => throw GeocodingFailedException::connection(),
    ]);
    $geocoder = new CachedGeocoder($adapter);
    $geocoder->geocode('Berlin');

    $this->travel(29)->days();

    expect(fn () => $geocoder->geocode('Berlin'))->toThrow(GeocodingFailedException::class);
    $this->assertDatabaseCount('geocode_cache', 0);
});

it('never hands out expired coordinates after a failed lookup', function () {
    $adapter = new FakeAdapter([
        new Coordinates(1.0, 2.0),
        fn () => throw GeocodingFailedException::connection(),
        null,
    ]);
    $geocoder = new CachedGeocoder($adapter);
    $geocoder->geocode('Berlin');

    $this->travel(40)->days();

    expect(fn () => $geocoder->geocode('Berlin'))->toThrow(GeocodingFailedException::class)
        ->and($geocoder->geocode('Berlin'))->toBeNull();
});

it('leaves a valid entry untouched when the refresh fails', function () {
    $adapter = new FakeAdapter([new Coordinates(1.0, 2.0), fn () => throw GeocodingFailedException::connection()]);
    $geocoder = new CachedGeocoder($adapter);
    $geocoder->geocode('Berlin');
    $before = GeocodeCacheEntry::query()->firstOrFail()->getAttributes();

    $this->travel(28)->days();

    expect(fn () => $geocoder->refresh(GeocodeQuery::make('Berlin')))->toThrow(GeocodingFailedException::class)
        ->and(GeocodeCacheEntry::query()->firstOrFail()->getAttributes())->toBe($before);
});

it('does not store a lookup without result', function () {
    $adapter = new FakeAdapter([null, null]);
    $geocoder = new CachedGeocoder($adapter);

    expect($geocoder->geocode('Nowhere'))->toBeNull()
        ->and($geocoder->geocode('Nowhere'))->toBeNull()
        ->and($adapter->calls())->toBe(2);

    $this->assertDatabaseCount('geocode_cache', 0);
});

it('does not store anything when the adapter fails', function () {
    $geocoder = new CachedGeocoder(new FakeAdapter([fn () => throw GeocodingFailedException::connection()]));

    expect(fn () => $geocoder->geocode('Berlin'))->toThrow(GeocodingFailedException::class);
    $this->assertDatabaseCount('geocode_cache', 0);
});

it('keeps separate entries for queries that differ in a parameter', function () {
    $adapter = new FakeAdapter([new Coordinates(1.0, 2.0), new Coordinates(3.0, 4.0), new Coordinates(5.0, 6.0)]);
    $geocoder = new CachedGeocoder($adapter);

    $geocoder->geocode(GeocodeQuery::make('Springfield', 'US'));
    $geocoder->geocode(GeocodeQuery::make('Springfield', 'CA'));
    $geocoder->geocode(GeocodeQuery::make('Springfield', 'US', 'de'));
    $geocoder->geocode(GeocodeQuery::make('SPRINGFIELD ', 'us'));

    expect($adapter->calls())->toBe(3);
    $this->assertDatabaseCount('geocode_cache', 3);
});

it('keeps separate entries for another data source', function () {
    $google = new FakeAdapter([new Coordinates(1.0, 2.0)]);
    $other = new FakeAdapter([new Coordinates(3.0, 4.0)], 'other');

    (new CachedGeocoder($google))->geocode('Berlin');
    $result = (new CachedGeocoder($other))->geocode('Berlin');

    expect($result->latitude)->toBe(3.0)
        ->and($result->source)->toBe('other');
    $this->assertDatabaseCount('geocode_cache', 2);
});

it('can leave the place id out', function () {
    config(['geocode-cache.store_place_id' => false]);

    $result = (new CachedGeocoder(FakeAdapter::returning(placeId: 'place-1')))->geocode('Berlin');

    expect($result->placeId)->toBeNull();
    $this->assertDatabaseHas('geocode_cache', ['place_id' => null]);
});

it('uses the configured number of days', function () {
    config(['geocode-cache.ttl_days' => 7]);

    $result = (new CachedGeocoder(FakeAdapter::returning()))->geocode('Berlin');

    expect($result->expiresAt->equalTo(now()->addDays(7)))->toBeTrue();
});

it('accepts the maximum of 30 days for Google', function () {
    config(['geocode-cache.ttl_days' => 30]);

    expect((new CachedGeocoder(FakeAdapter::returning()))->retentionDays())->toBe(30);
});

it('rejects a retention that does not fit', function (mixed $days) {
    config(['geocode-cache.ttl_days' => $days]);
    $adapter = FakeAdapter::returning();

    expect(fn () => (new CachedGeocoder($adapter))->geocode('Berlin'))->toThrow(InvalidArgumentException::class)
        ->and($adapter->calls())->toBe(0);
    $this->assertDatabaseCount('geocode_cache', 0);
})->with([
    'zero' => [0],
    'negative' => [-1],
    'more than Google allows' => [31],
    'a string' => ['29'],
    'null' => [null],
]);

it('allows a longer retention for an adapter without a maximum', function () {
    config(['geocode-cache.ttl_days' => 90]);

    $result = (new CachedGeocoder(new FakeAdapter([new Coordinates(1.0, 2.0)], 'other', maximumRetentionDays: null)))->geocode('Berlin');

    expect($result->expiresAt->equalTo(now()->addDays(90)))->toBeTrue();
});

it('never exceeds the maximum that the adapter reports', function () {
    $geocoder = new CachedGeocoder(new FakeAdapter(source: 'other', maximumRetentionDays: 14));

    config(['geocode-cache.ttl_days' => 15]);
    expect(fn () => $geocoder->retentionDays())->toThrow(InvalidArgumentException::class, 'must not exceed 14 days');

    config(['geocode-cache.ttl_days' => 14]);
    expect($geocoder->retentionDays())->toBe(14);
});

it('rejects an adapter that reports a maximum below one day', function (int $days) {
    expect(fn () => (new CachedGeocoder(new FakeAdapter(maximumRetentionDays: $days)))->retentionDays())
        ->toThrow(InvalidArgumentException::class, 'maximum retention');
})->with([0, -5]);

it('uses the retention of the source of the adapter', function () {
    config(['geocode-cache.ttl_days' => 29, 'geocode-cache.source_ttl_days' => ['other' => 365]]);

    $other = new CachedGeocoder(new FakeAdapter([new Coordinates(1.0, 2.0)], 'other', maximumRetentionDays: null));

    expect($other->retentionDays())->toBe(365)
        ->and($other->geocode('Berlin')->expiresAt->equalTo(now()->addDays(365)))->toBeTrue()
        ->and((new CachedGeocoder(FakeAdapter::returning()))->retentionDays())->toBe(29);
});

it('checks the retention of a source against the maximum of the adapter', function () {
    config(['geocode-cache.source_ttl_days' => ['google' => 31]]);

    expect(fn () => (new CachedGeocoder(FakeAdapter::returning()))->retentionDays())
        ->toThrow(InvalidArgumentException::class, 'must not exceed 30 days');

    config(['geocode-cache.source_ttl_days' => ['google' => 10]]);

    expect((new CachedGeocoder(FakeAdapter::returning()))->retentionDays())->toBe(10);
});

it('rejects a retention of a source that does not fit', function (mixed $days) {
    config(['geocode-cache.source_ttl_days' => ['other' => $days]]);
    $adapter = new FakeAdapter([new Coordinates(1.0, 2.0)], 'other', maximumRetentionDays: null);

    expect(fn () => (new CachedGeocoder($adapter))->geocode('Berlin'))->toThrow(InvalidArgumentException::class, 'source_ttl_days.other')
        ->and($adapter->calls())->toBe(0);
    $this->assertDatabaseCount('geocode_cache', 0);
})->with([
    'zero' => [0],
    'negative' => [-1],
    'text' => ['many'],
    'fraction' => [1.5],
    'numeric text' => ['365'],
]);

it('rejects a source retention that is not a map', function (mixed $setting) {
    config(['geocode-cache.source_ttl_days' => $setting]);

    expect(fn () => (new CachedGeocoder(FakeAdapter::returning()))->retentionDays())
        ->toThrow(InvalidArgumentException::class, 'source_ttl_days');
})->with(['number' => [365], 'text' => ['mapbox'], 'flag' => [true]]);

it('does not read the default retention for a source that has its own', function () {
    config(['geocode-cache.ttl_days' => 0, 'geocode-cache.source_ttl_days' => ['other' => 90]]);

    expect((new CachedGeocoder(new FakeAdapter(source: 'other', maximumRetentionDays: null)))->retentionDays())->toBe(90);
});

it('does not return an entry that was kept longer than the adapter allows', function () {
    // Another adapter without a maximum wrote an entry for the same source.
    config(['geocode-cache.ttl_days' => 90]);
    (new CachedGeocoder(new FakeAdapter([new Coordinates(1.0, 2.0, 'old')], maximumRetentionDays: null)))->geocode('Berlin');

    config(['geocode-cache.ttl_days' => 29]);
    $limited = new FakeAdapter([new Coordinates(3.0, 4.0, 'new')]);
    $result = (new CachedGeocoder($limited))->geocode('Berlin');

    expect($limited->calls())->toBe(1)
        ->and($result->latitude)->toBe(3.0)
        ->and($result->expiresAt->equalTo(now()->addDays(29)))->toBeTrue();
    $this->assertDatabaseCount('geocode_cache', 1);
});

it('returns an entry that is kept exactly as long as the adapter allows', function () {
    config(['geocode-cache.ttl_days' => 30]);
    $adapter = FakeAdapter::returning();
    $geocoder = new CachedGeocoder($adapter);
    $geocoder->geocode('Berlin');

    $this->travel(29)->days();
    $geocoder->geocode('Berlin');

    expect($adapter->calls())->toBe(1);
});

it('lets a source whose name contains a dot have its own retention', function () {
    config(['geocode-cache.source_ttl_days' => ['my.provider' => 90]]);

    expect((new CachedGeocoder(new FakeAdapter(source: 'my.provider', maximumRetentionDays: null)))->retentionDays())->toBe(90);
});

it('does not move the end of the period with a change of the clock', function () {
    config(['app.timezone' => 'Europe/Berlin']);
    Date::setTestNow('2026-10-01 12:00:00');

    $result = (new CachedGeocoder(FakeAdapter::returning()))->geocode('Berlin');

    expect($result->fetchedAt->diffInSeconds($result->expiresAt))->toBe(29 * 86_400.0);
});

it('leaves the time of the last request alone when the refresh is off', function () {
    $geocoder = new CachedGeocoder(FakeAdapter::returning());
    $geocoder->geocode('Berlin');

    $this->travel(5)->days();
    $geocoder->geocode('Berlin');

    expect(GeocodeCacheEntry::query()->firstOrFail()->last_requested_at->equalTo(now()->subDays(5)))->toBeTrue();
});

it('rounds the coordinates to the configured precision', function () {
    config(['geocode-cache.precision' => 4]);

    $result = (new CachedGeocoder(FakeAdapter::returning(52.5219184, 13.4132147)))->geocode('Berlin');

    expect($result->latitude)->toBe(52.5219)
        ->and($result->longitude)->toBe(13.4132);
    $this->assertDatabaseHas('geocode_cache', ['latitude' => 52.5219, 'longitude' => 13.4132]);
});

it('returns the same coordinates from the cache as from the lookup', function () {
    config(['geocode-cache.precision' => 2]);
    $geocoder = new CachedGeocoder(FakeAdapter::returning(52.5219184, 13.4132147));

    $fresh = $geocoder->geocode('Berlin');
    $cached = $geocoder->geocode('Berlin');

    expect($cached->latitude)->toBe($fresh->latitude)->toBe(52.52)
        ->and($cached->longitude)->toBe($fresh->longitude)->toBe(13.41);
});

it('allows a precision of zero decimals', function () {
    config(['geocode-cache.precision' => 0]);

    expect((new CachedGeocoder(FakeAdapter::returning(52.5219184, 13.4132147)))->geocode('Berlin')->latitude)->toBe(53.0);
});

it('uses the maximum of the adapter without a precision setting', function () {
    $geocoder = new CachedGeocoder(FakeAdapter::returning(52.5219184, 13.4132147));

    expect($geocoder->precision())->toBe(7)
        ->and($geocoder->geocode('Berlin')->latitude)->toBe(52.5219184);
});

it('caps the precision at the maximum of the adapter', function () {
    config(['geocode-cache.precision' => 5]);
    $adapter = new FakeAdapter([new Coordinates(1.0, 2.0)], 'other', 3);
    $geocoder = new CachedGeocoder($adapter);

    expect(fn () => $geocoder->geocode('Berlin'))->toThrow(InvalidArgumentException::class, 'from 0 to 3')
        ->and($adapter->calls())->toBe(0);

    config(['geocode-cache.precision' => null]);
    expect($geocoder->precision())->toBe(3);
});

it('never exceeds the seven decimals the table stores', function () {
    $geocoder = new CachedGeocoder(new FakeAdapter([], 'other', 12));

    expect($geocoder->precision())->toBe(7);
});

it('rejects a precision that does not fit', function (mixed $precision) {
    config(['geocode-cache.precision' => $precision]);
    $adapter = FakeAdapter::returning();

    expect(fn () => (new CachedGeocoder($adapter))->geocode('Berlin'))->toThrow(InvalidArgumentException::class, 'from 0 to 7')
        ->and($adapter->calls())->toBe(0);
    $this->assertDatabaseCount('geocode_cache', 0);
})->with([
    'negative' => [-1],
    'more than Google delivers' => [8],
    'a string' => ['4'],
    'text' => ['many'],
    'a float' => [4.5],
]);

it('rejects an adapter with a negative maximum precision', function () {
    $adapter = new FakeAdapter([new Coordinates(1.0, 2.0)], 'other', -1);

    expect(fn () => (new CachedGeocoder($adapter))->geocode('Berlin'))->toThrow(InvalidArgumentException::class, 'negative maximum')
        ->and($adapter->calls())->toBe(0);
});

it('does not write the result of a refresh into an entry that expired during the service call', function () {
    $adapter = new FakeAdapter([new Coordinates(1.0, 2.0)]);
    $geocoder = new CachedGeocoder($adapter);
    $geocoder->geocode('Berlin');
    $adapter->queue(function () {
        $this->travel(29)->days();

        return new Coordinates(3.0, 4.0);
    });

    expect($geocoder->refresh(GeocodeQuery::make('Berlin')))->toBe(RefreshOutcome::Skipped);
    expect(GeocodeCacheEntry::query()->firstOrFail()->latitude)->toBe(1.0);
});
