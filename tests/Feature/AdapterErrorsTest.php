<?php

declare(strict_types=1);

use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use Taldres\GeocodeCache\CachedGeocoder;
use Taldres\GeocodeCache\Data\Coordinates;
use Taldres\GeocodeCache\Data\GeocodeQuery;
use Taldres\GeocodeCache\Events\GeocodeLookupFailed;
use Taldres\GeocodeCache\Events\GeocodeServiceCalled;
use Taldres\GeocodeCache\Exceptions\GeocodingLockTimeoutException;
use Taldres\GeocodeCache\Models\GeocodeCacheEntry;
use Taldres\GeocodeCache\Tests\Fixtures\FakeAdapter;

beforeEach(function () {
    $this->travelTo('2026-10-17 12:00:00');
    config([
        'logging.channels.geocode' => ['driver' => 'monolog', 'handler' => TestHandler::class],
        'geocode-cache.logging.channel' => 'geocode',
    ]);
});

/**
 * @return list<array{level_name: string, message: string, context: array<string, mixed>}>
 */
function adapterErrorRecords(): array
{
    $logger = Log::channel('geocode')->getLogger();
    assert($logger instanceof Logger);
    $handler = $logger->getHandlers()[0];
    assert($handler instanceof TestHandler);

    return array_map(fn ($record) => [
        'level_name' => $record->level->getName(),
        'message' => $record->message,
        'context' => $record->context,
    ], $handler->getRecords());
}

function queryLock(string $search): string
{
    return 'geocode-cache:'.GeocodeQuery::make($search)->hash('google');
}

it('passes on an error of the adapter unchanged', function (Throwable $error) {
    $geocoder = new CachedGeocoder(new FakeAdapter([fn () => throw $error]));

    try {
        $geocoder->geocode('Berlin');

        $this->fail('The error was swallowed.');
    } catch (Throwable $thrown) {
        expect($thrown)->toBe($error);
    }

    $this->assertDatabaseCount('geocode_cache', 0);
})->with([
    'runtime exception' => fn () => new RuntimeException('boom'),
    'type error' => fn () => new TypeError('boom'),
    'lock timeout of the adapter' => fn () => new LockTimeoutException('the adapter waited for its own lock'),
]);

it('does not mistake a lock timeout of the adapter for the lock of the cache', function () {
    $adapter = new FakeAdapter([fn () => throw new LockTimeoutException]);
    $geocoder = new CachedGeocoder($adapter);

    expect(fn () => $geocoder->geocode('Berlin'))->toThrow(LockTimeoutException::class)
        ->and($adapter->calls())->toBe(1);
});

it('does not mistake a lock timeout of the adapter for the lock of the cache when refreshing', function () {
    $adapter = new FakeAdapter([new Coordinates(1.0, 2.0), fn () => throw new LockTimeoutException]);
    $geocoder = new CachedGeocoder($adapter);
    $geocoder->geocode('Berlin');

    expect(fn () => $geocoder->refresh(GeocodeQuery::make('Berlin')))->toThrow(LockTimeoutException::class)
        ->and(GeocodeCacheEntry::query()->count())->toBe(1);
});

it('still reports the lock of the cache with the lock timeout exception', function () {
    config(['geocode-cache.lock.wait' => 0]);
    Cache::lock(queryLock('Berlin'), 60)->get();
    $adapter = FakeAdapter::returning();

    expect(fn () => (new CachedGeocoder($adapter))->geocode('Berlin'))->toThrow(GeocodingLockTimeoutException::class)
        ->and(fn () => (new CachedGeocoder($adapter))->refresh(GeocodeQuery::make('Berlin')))->toThrow(GeocodingLockTimeoutException::class)
        ->and($adapter->calls())->toBe(0);
});

it('logs and announces an unexpected error of the adapter without its message', function () {
    Event::fake([GeocodeLookupFailed::class, GeocodeServiceCalled::class]);
    $geocoder = new CachedGeocoder(new FakeAdapter([
        fn () => throw new RuntimeException('GET https://maps.example/api?address=Secret+Street&key=SECRET-KEY failed'),
    ]));

    expect(fn () => $geocoder->geocode('Secret Street 1, Berlin'))->toThrow(RuntimeException::class);

    expect(adapterErrorRecords())->toBe([[
        'level_name' => 'WARNING',
        'message' => 'The geocoding lookup failed.',
        'context' => ['source' => 'google', 'reason' => 'unexpected'],
    ]]);
    Event::assertDispatchedTimes(GeocodeLookupFailed::class, 1);
    Event::assertDispatched(GeocodeLookupFailed::class, fn (GeocodeLookupFailed $event): bool => $event->source === 'google' && $event->reason === 'unexpected');
    Event::assertNotDispatched(GeocodeServiceCalled::class);
});

it('announces an unexpected error of a refresh and leaves the entry alone', function () {
    $geocoder = new CachedGeocoder(new FakeAdapter([new Coordinates(1.0, 2.0), fn () => throw new RuntimeException('boom')]));
    $query = GeocodeQuery::make('Berlin');
    $geocoder->geocode($query);
    $before = GeocodeCacheEntry::query()->firstOrFail()->getAttributes();
    Event::fake([GeocodeLookupFailed::class]);

    expect(fn () => $geocoder->refresh($query))->toThrow(RuntimeException::class);

    Event::assertDispatched(GeocodeLookupFailed::class, fn (GeocodeLookupFailed $event): bool => $event->reason === 'unexpected');
    expect(GeocodeCacheEntry::query()->firstOrFail()->getAttributes())->toBe($before);
});

it('releases the lock after an unexpected error', function () {
    $geocoder = new CachedGeocoder(new FakeAdapter([fn () => throw new RuntimeException('boom'), new Coordinates(1.0, 2.0)]));

    expect(fn () => $geocoder->geocode('Berlin'))->toThrow(RuntimeException::class);

    $lock = Cache::lock(queryLock('Berlin'), 5);
    expect($lock->get())->toBeTrue();
    $lock->release();
    expect($geocoder->geocode('Berlin')->latitude)->toBe(1.0);
});

it('does not announce the exceptions of the package as unexpected', function () {
    Event::fake([GeocodeLookupFailed::class]);
    config(['geocode-cache.ttl_days' => 0]);

    expect(fn () => (new CachedGeocoder(FakeAdapter::returning()))->geocode('Berlin'))->toThrow(InvalidArgumentException::class);

    Event::assertNothingDispatched();
    expect(adapterErrorRecords())->toBe([]);
});
