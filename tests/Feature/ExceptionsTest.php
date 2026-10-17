<?php

declare(strict_types=1);

use Illuminate\Contracts\Cache\Store;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Sleep;
use Taldres\GeocodeCache\Adapters\GoogleAdapter;
use Taldres\GeocodeCache\CachedGeocoder;
use Taldres\GeocodeCache\Data\GeocodeQuery;
use Taldres\GeocodeCache\Data\PlaceIdQuery;
use Taldres\GeocodeCache\Exceptions\GeocodeCacheException;
use Taldres\GeocodeCache\Exceptions\GeocodingFailedException;
use Taldres\GeocodeCache\Exceptions\GeocodingLockTimeoutException;
use Taldres\GeocodeCache\Exceptions\InvalidConfigurationException;
use Taldres\GeocodeCache\Exceptions\UnsupportedQueryException;
use Taldres\GeocodeCache\Facades\GeocodeCache;
use Taldres\GeocodeCache\Tests\Fixtures\FakeAdapter;

beforeEach(function () {
    $this->travelTo('2026-10-17 12:00:00');
});

it('marks every failure of the service with the package interface', function (GeocodingFailedException $exception) {
    expect($exception)->toBeInstanceOf(GeocodeCacheException::class)
        ->toBeInstanceOf(RuntimeException::class);
})->with([
    'connection' => fn () => GeocodingFailedException::connection(),
    'http status' => fn () => GeocodingFailedException::httpStatus(503),
    'service status' => fn () => GeocodingFailedException::serviceStatus('REQUEST_DENIED'),
    'malformed response' => fn () => GeocodingFailedException::malformedResponse(),
    'lock timeout' => fn () => GeocodingFailedException::lockTimeout(),
]);

it('has a lock timeout that is still a failed geocoding with its reason', function () {
    $exception = GeocodingFailedException::lockTimeout();

    expect($exception)->toBeInstanceOf(GeocodingLockTimeoutException::class)
        ->toBeInstanceOf(GeocodingFailedException::class)
        ->and($exception->reason)->toBe('lock_timeout')
        ->and($exception->getMessage())->toBe('Another process is still looking up the same query. Try again in a moment.');
});

it('does not turn the other failures into lock timeouts', function () {
    expect(GeocodingFailedException::connection())->not->toBeInstanceOf(GeocodingLockTimeoutException::class);
});

it('throws the lock timeout exception when the lock stays taken', function () {
    config(['geocode-cache.lock.wait' => 1]);
    Sleep::fake(syncWithCarbon: true);
    Cache::lock('geocode-cache:'.GeocodeQuery::make('Berlin')->hash('google'), 60)->get();
    $adapter = FakeAdapter::returning();

    expect(fn () => (new CachedGeocoder($adapter))->geocode('Berlin'))->toThrow(GeocodingLockTimeoutException::class)
        ->and($adapter->calls())->toBe(0);
});

it('throws the lock timeout exception when a refresh cannot get the lock', function () {
    config(['geocode-cache.lock.wait' => 1]);
    Sleep::fake(syncWithCarbon: true);
    Cache::lock('geocode-cache:'.GeocodeQuery::make('Berlin')->hash('google'), 60)->get();

    expect(fn () => (new CachedGeocoder(FakeAdapter::returning()))->refresh(GeocodeQuery::make('Berlin')))
        ->toThrow(GeocodingLockTimeoutException::class);
});

it('reports an invalid setting with the configuration exception', function (string $key, mixed $value) {
    config([$key => $value]);
    $adapter = FakeAdapter::returning();

    try {
        (new CachedGeocoder($adapter))->geocode('Berlin');

        $this->fail('The invalid setting was accepted.');
    } catch (InvalidConfigurationException $exception) {
        expect($exception)->toBeInstanceOf(InvalidArgumentException::class)
            ->toBeInstanceOf(GeocodeCacheException::class)
            ->and($adapter->calls())->toBe(0);
    }
})->with([
    'ttl_days' => ['geocode-cache.ttl_days', 0],
    'ttl_days above the maximum' => ['geocode-cache.ttl_days', 31],
    'source_ttl_days' => ['geocode-cache.source_ttl_days', 'google'],
    'source_ttl_days entry' => ['geocode-cache.source_ttl_days', ['google' => 'many']],
    'precision' => ['geocode-cache.precision', 99],
    'lock.seconds' => ['geocode-cache.lock.seconds', 0],
    'lock.wait' => ['geocode-cache.lock.wait', -1],
]);

it('reports an adapter with an unusable maximum with the configuration exception', function (FakeAdapter $adapter) {
    expect(fn () => (new CachedGeocoder($adapter))->geocode('Berlin'))->toThrow(InvalidConfigurationException::class)
        ->and($adapter->calls())->toBe(0);
})->with([
    'retention below one day' => fn () => new FakeAdapter(maximumRetentionDays: 0),
    'negative precision' => fn () => new FakeAdapter(maximumPrecision: -1),
]);

it('reports a lock store without atomic locks with the configuration exception', function () {
    Cache::extend('nolock', fn () => Cache::repository(new class implements Store
    {
        public function get($key): mixed
        {
            return null;
        }

        public function many(array $keys): array
        {
            return [];
        }

        public function put($key, $value, $seconds): bool
        {
            return true;
        }

        public function putMany(array $values, $seconds): bool
        {
            return true;
        }

        public function increment($key, $value = 1): int|bool
        {
            return false;
        }

        public function decrement($key, $value = 1): int|bool
        {
            return false;
        }

        public function touch($key, $seconds): bool
        {
            return true;
        }

        public function forever($key, $value): bool
        {
            return true;
        }

        public function forget($key): bool
        {
            return true;
        }

        public function flush(): bool
        {
            return true;
        }

        public function getPrefix(): string
        {
            return '';
        }
    }));
    config(['cache.stores.nolock' => ['driver' => 'nolock'], 'geocode-cache.lock.store' => 'nolock']);
    $adapter = FakeAdapter::returning();

    expect(fn () => (new CachedGeocoder($adapter))->geocode('Berlin'))->toThrow(InvalidConfigurationException::class, 'atomic locks')
        ->and($adapter->calls())->toBe(0);
});

it('reports a driver timeout that reaches the lock with the configuration exception', function () {
    config(['geocode-cache.drivers.google.timeout' => 30, 'geocode-cache.lock.seconds' => 30]);

    GeocodeCache::driver('google');
})->throws(InvalidConfigurationException::class, 'timeout');

it('reports a custom driver of the wrong type with the configuration exception', function () {
    GeocodeCache::extend('custom', fn () => new stdClass);

    GeocodeCache::driver('custom');
})->throws(InvalidConfigurationException::class, 'must return a CachedGeocoder or an Adapter');

it('reports a missing Google API key with the configuration exception', function () {
    new GoogleAdapter('');
})->throws(InvalidConfigurationException::class, 'API key');

it('reports a place ID for an adapter that cannot look it up with the unsupported query exception', function () {
    $adapter = new FakeAdapter;
    $geocoder = new CachedGeocoder($adapter);
    $query = PlaceIdQuery::make('ChIJ-abc');

    foreach ([
        fn () => $geocoder->geocode($query),
        fn () => $geocoder->refresh($query),
        fn () => $geocoder->refreshUsing('hash', $query),
    ] as $call) {
        try {
            $call();

            $this->fail('The place ID was accepted.');
        } catch (UnsupportedQueryException $exception) {
            expect($exception)->toBeInstanceOf(LogicException::class)
                ->toBeInstanceOf(GeocodeCacheException::class);
        }
    }

    expect($adapter->calls())->toBe(0);
});

it('lets one catch block handle every exception the package defines for the caller', function (Closure $call) {
    $caught = null;

    try {
        $call();
    } catch (GeocodeCacheException $exception) {
        $caught = $exception;
    }

    expect($caught)->toBeInstanceOf(Throwable::class);
})->with([
    'failed lookup' => fn () => (new CachedGeocoder(new FakeAdapter([fn () => throw GeocodingFailedException::connection()])))->geocode('Berlin'),
    'invalid setting' => function () {
        config(['geocode-cache.ttl_days' => 0]);
        (new CachedGeocoder(FakeAdapter::returning()))->geocode('Berlin');
    },
    'unsupported query' => fn () => (new CachedGeocoder(new FakeAdapter))->geocode(PlaceIdQuery::make('ChIJ-abc')),
]);
