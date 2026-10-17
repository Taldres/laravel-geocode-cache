<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use Taldres\GeocodeCache\CachedGeocoder;
use Taldres\GeocodeCache\Data\Coordinates;
use Taldres\GeocodeCache\Data\GeocodeQuery;
use Taldres\GeocodeCache\Data\PlaceIdQuery;
use Taldres\GeocodeCache\Enums\RefreshOutcome;
use Taldres\GeocodeCache\Events\GeocodeLookupFailed;
use Taldres\GeocodeCache\Events\GeocodeServiceCalled;
use Taldres\GeocodeCache\Exceptions\GeocodingFailedException;
use Taldres\GeocodeCache\Models\GeocodeCacheEntry;
use Taldres\GeocodeCache\Tests\Fixtures\FakeAdapter;
use Taldres\GeocodeCache\Tests\Fixtures\FakePlaceAdapter;

beforeEach(function () {
    $this->travelTo('2026-10-17 12:00:00');
});

it('announces a call of the service that found coordinates', function () {
    Event::fake([GeocodeServiceCalled::class, GeocodeLookupFailed::class]);

    (new CachedGeocoder(FakeAdapter::returning(52.5219184, 13.4132147, 'place-secret')))->geocode('Secret Street 1, Berlin');

    Event::assertDispatchedTimes(GeocodeServiceCalled::class, 1);
    Event::assertDispatched(GeocodeServiceCalled::class, fn (GeocodeServiceCalled $event): bool => $event->source === 'google' && $event->found === true);
    Event::assertNotDispatched(GeocodeLookupFailed::class);
});

it('announces a call of the service that found nothing', function () {
    Event::fake([GeocodeServiceCalled::class]);

    expect((new CachedGeocoder(new FakeAdapter([null])))->geocode('Nowhere'))->toBeNull();

    Event::assertDispatched(GeocodeServiceCalled::class, fn (GeocodeServiceCalled $event): bool => $event->source === 'google' && $event->found === false);
});

it('announces the source of the adapter', function () {
    Event::fake([GeocodeServiceCalled::class]);

    (new CachedGeocoder(new FakeAdapter([new Coordinates(1.0, 2.0)], source: 'mapbox', maximumRetentionDays: null)))->geocode('Berlin');

    Event::assertDispatched(GeocodeServiceCalled::class, fn (GeocodeServiceCalled $event): bool => $event->source === 'mapbox');
});

it('does not announce an answer from the cache', function () {
    $geocoder = new CachedGeocoder(FakeAdapter::returning());
    $geocoder->geocode('Berlin');
    Event::fake([GeocodeServiceCalled::class, GeocodeLookupFailed::class]);

    $geocoder->geocode('Berlin');

    Event::assertNotDispatched(GeocodeServiceCalled::class);
    Event::assertNotDispatched(GeocodeLookupFailed::class);
});

it('announces a failed lookup with its reason and still throws', function () {
    Event::fake([GeocodeServiceCalled::class, GeocodeLookupFailed::class]);
    $geocoder = new CachedGeocoder(new FakeAdapter([fn () => throw GeocodingFailedException::httpStatus(503)]));

    expect(fn () => $geocoder->geocode('Berlin'))->toThrow(GeocodingFailedException::class, 'HTTP status 503');

    Event::assertDispatchedTimes(GeocodeLookupFailed::class, 1);
    Event::assertDispatched(GeocodeLookupFailed::class, fn (GeocodeLookupFailed $event): bool => $event->source === 'google' && $event->reason === 'http_503');
    Event::assertNotDispatched(GeocodeServiceCalled::class);
    $this->assertDatabaseCount('geocode_cache', 0);
});

it('announces the calls of a refresh', function () {
    $geocoder = new CachedGeocoder(new FakeAdapter([
        new Coordinates(1.0, 2.0),
        new Coordinates(3.0, 4.0),
        fn () => throw GeocodingFailedException::connection(),
    ]));
    $query = GeocodeQuery::make('Berlin');
    $geocoder->geocode($query);
    Event::fake([GeocodeServiceCalled::class, GeocodeLookupFailed::class]);

    $geocoder->refresh($query);

    expect(fn () => $geocoder->refresh($query))->toThrow(GeocodingFailedException::class);

    Event::assertDispatchedTimes(GeocodeServiceCalled::class, 1);
    Event::assertDispatched(GeocodeLookupFailed::class, fn (GeocodeLookupFailed $event): bool => $event->reason === 'connection');
});

it('announces the call of a place ID lookup', function () {
    Event::fake([GeocodeServiceCalled::class]);

    (new CachedGeocoder(new FakePlaceAdapter([new Coordinates(1.0, 2.0, 'ChIJ-abc')])))->geocode(PlaceIdQuery::make('ChIJ-abc'));

    Event::assertDispatchedTimes(GeocodeServiceCalled::class, 1);
});

it('does not announce anything when the settings are invalid', function () {
    Event::fake([GeocodeServiceCalled::class, GeocodeLookupFailed::class]);
    config(['geocode-cache.ttl_days' => 0]);

    expect(fn () => (new CachedGeocoder(FakeAdapter::returning()))->geocode('Berlin'))->toThrow(InvalidArgumentException::class);

    Event::assertNothingDispatched();
});

it('does not announce a lock timeout, because the service was not asked', function () {
    Event::fake([GeocodeServiceCalled::class, GeocodeLookupFailed::class]);
    config(['geocode-cache.lock.wait' => 1]);
    Sleep::fake(syncWithCarbon: true);
    Cache::lock('geocode-cache:'.GeocodeQuery::make('Berlin')->hash('google'), 60)->get();

    expect(fn () => (new CachedGeocoder(FakeAdapter::returning()))->geocode('Berlin'))->toThrow(GeocodingFailedException::class);

    Event::assertNothingDispatched();
});

it('dispatches after the lock of the query was released', function (string $case) {
    $key = 'geocode-cache:'.GeocodeQuery::make('Berlin')->hash('google');
    $geocoder = new CachedGeocoder(new FakeAdapter([
        new Coordinates(1.0, 2.0),
        $case === 'failure' ? fn () => throw GeocodingFailedException::connection() : new Coordinates(3.0, 4.0),
    ]));

    if ($case === 'refresh' || $case === 'failure') {
        $geocoder->geocode('Berlin');
    }

    $lockWasFree = [];
    Event::listen([GeocodeServiceCalled::class, GeocodeLookupFailed::class], function () use ($key, &$lockWasFree) {
        $lock = Cache::lock($key, 5);
        $lockWasFree[] = $lock->get();
        $lock->release();
    });

    try {
        $case === 'lookup' ? $geocoder->geocode('Berlin') : $geocoder->refresh(GeocodeQuery::make('Berlin'));
    } catch (GeocodingFailedException) {
        // The event of the failure is what this case is about.
    }

    expect($lockWasFree)->toBe([true]);
})->with(['lookup', 'refresh', 'failure']);

it('lets a listener refresh the entry, which takes the lock again', function () {
    config(['geocode-cache.lock.wait' => 0]);
    $adapter = new FakeAdapter([new Coordinates(1.0, 2.0), new Coordinates(3.0, 4.0)]);
    $geocoder = new CachedGeocoder($adapter);
    $outcome = null;
    $refreshing = false;
    Event::listen(GeocodeServiceCalled::class, function () use ($geocoder, &$outcome, &$refreshing) {
        // The refresh calls the service and so dispatches the event again, which must not start another one.
        if ($refreshing) {
            return;
        }

        $refreshing = true;
        // Refreshing always takes the lock, so this fails at once if the lock was still held.
        $outcome = $geocoder->refresh(GeocodeQuery::make('Berlin'));
    });

    $geocoder->geocode('Berlin');

    expect($outcome)->toBe(RefreshOutcome::Refreshed)
        ->and($adapter->calls())->toBe(2);
});

it('rolls back what a failing listener wrote inside the transaction of the application', function () {
    $hash = hash('sha256', 'written by the listener');
    Event::listen(GeocodeServiceCalled::class, function () use ($hash) {
        GeocodeCacheEntry::query()->create([
            'query_hash' => $hash,
            'source' => 'google',
            'latitude' => 1.0,
            'longitude' => 2.0,
            'fetched_at' => now(),
            'expires_at' => now()->addDay(),
            'last_requested_at' => now(),
        ]);

        throw new RuntimeException('boom');
    });

    DB::transaction(fn () => (new CachedGeocoder(FakeAdapter::returning()))->geocode('Berlin'));

    // The lookup itself stays, the half-finished write of the listener does not.
    $this->assertDatabaseCount('geocode_cache', 1);
    $this->assertDatabaseMissing('geocode_cache', ['query_hash' => $hash]);
});

it('reports a failing listener to the exception handler', function () {
    Exceptions::fake();
    Event::listen(GeocodeServiceCalled::class, fn () => throw new RuntimeException('boom'));

    expect((new CachedGeocoder(FakeAdapter::returning()))->geocode('Berlin'))->not->toBeNull();

    Exceptions::assertReported(RuntimeException::class);
});

it('keeps the outcome of a refresh when a listener fails', function () {
    $geocoder = new CachedGeocoder(new FakeAdapter([new Coordinates(1.0, 2.0), new Coordinates(3.0, 4.0)]));
    $geocoder->geocode('Berlin');
    Exceptions::fake();
    Event::listen(GeocodeServiceCalled::class, fn () => throw new RuntimeException('boom'));

    expect($geocoder->refresh(GeocodeQuery::make('Berlin')))->toBe(RefreshOutcome::Refreshed);
});

it('carries nothing but the fields it is meant to carry', function (string $event, array $fields) {
    $properties = array_map(fn (ReflectionProperty $property): string => $property->getName(), (new ReflectionClass($event))->getProperties());

    expect($properties)->toEqualCanonicalizing($fields)
        ->and((new ReflectionClass($event))->isReadOnly())->toBeTrue()
        ->and((new ReflectionClass($event))->isFinal())->toBeTrue();
})->with([
    'service called' => [GeocodeServiceCalled::class, ['source', 'found']],
    'lookup failed' => [GeocodeLookupFailed::class, ['source', 'reason']],
]);

describe('a listener that fails', function () {
    beforeEach(function () {
        config([
            'logging.channels.geocode' => ['driver' => 'monolog', 'handler' => TestHandler::class],
            'geocode-cache.logging.channel' => 'geocode',
        ]);
    });

    /**
     * @return list<array{level_name: string, message: string, context: array<string, mixed>}>
     */
    function warnings(): array
    {
        $logger = Log::channel('geocode')->getLogger();
        assert($logger instanceof Logger);
        $handler = $logger->getHandlers()[0];
        assert($handler instanceof TestHandler);

        return array_values(array_filter(
            array_map(fn ($record) => [
                'level_name' => $record->level->getName(),
                'message' => $record->message,
                'context' => $record->context,
            ], $handler->getRecords()),
            fn (array $record): bool => $record['message'] === 'A geocode cache event listener failed.',
        ));
    }

    it('does not stop a lookup that was paid for', function () {
        Event::listen(GeocodeServiceCalled::class, fn () => throw new RuntimeException('Secret Street 1 failed'));
        $geocoder = new CachedGeocoder(FakeAdapter::returning(1.5, 2.5));

        expect($geocoder->geocode('Secret Street 1, Berlin')->latitude)->toBe(1.5)
            ->and($geocoder->geocode('Secret Street 1, Berlin')->latitude)->toBe(1.5);
        $this->assertDatabaseCount('geocode_cache', 1);
        expect(warnings())->toBe([[
            'level_name' => 'WARNING',
            'message' => 'A geocode cache event listener failed.',
            'context' => ['event' => GeocodeServiceCalled::class, 'exception' => RuntimeException::class],
        ]]);
    });

    it('releases the lock although a listener failed', function () {
        Event::listen(GeocodeLookupFailed::class, fn () => throw new RuntimeException('boom'));
        $geocoder = new CachedGeocoder(new FakeAdapter([fn () => throw GeocodingFailedException::connection(), new Coordinates(1.0, 2.0)]));

        expect(fn () => $geocoder->geocode('Berlin'))->toThrow(GeocodingFailedException::class);

        $lock = Cache::lock('geocode-cache:'.GeocodeQuery::make('Berlin')->hash('google'), 5);
        expect($lock->get())->toBeTrue();
        $lock->release();
        expect($geocoder->geocode('Berlin')->latitude)->toBe(1.0);
    });

    it('does not replace the exception of a failed lookup', function () {
        Event::listen(GeocodeLookupFailed::class, fn () => throw new RuntimeException('boom'));
        $geocoder = new CachedGeocoder(new FakeAdapter([fn () => throw GeocodingFailedException::connection()]));

        expect(fn () => $geocoder->geocode('Berlin'))->toThrow(GeocodingFailedException::class, 'could not be reached');
        expect(warnings())->toHaveCount(1);
    });
});
