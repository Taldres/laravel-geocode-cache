<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use Taldres\GeocodeCache\CachedGeocoder;
use Taldres\GeocodeCache\Data\Coordinates;
use Taldres\GeocodeCache\Data\GeocodeQuery;
use Taldres\GeocodeCache\Exceptions\GeocodingFailedException;
use Taldres\GeocodeCache\Facades\GeocodeCache;
use Taldres\GeocodeCache\Models\GeocodeCacheEntry;
use Taldres\GeocodeCache\Support\GeocodeLog;
use Taldres\GeocodeCache\Tests\Fixtures\FakeAdapter;
use Taldres\GeocodeCache\Tests\Fixtures\FakePlaceAdapter;

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
function loggedRecords(): array
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

it('logs nothing unless a channel is configured', function (mixed $channel) {
    config(['geocode-cache.logging.channel' => $channel]);

    (new CachedGeocoder(FakeAdapter::returning()))->geocode('Berlin');

    expect(loggedRecords())->toBe([]);
})->with([[null], ['']]);

it('logs a lookup without its data', function () {
    (new CachedGeocoder(FakeAdapter::returning(52.5219184, 13.4132147, 'place-secret')))->geocode('Secret Street 1, Berlin');

    expect(loggedRecords())->toBe([[
        'level_name' => 'DEBUG',
        'message' => 'The geocoding service was asked.',
        'context' => ['source' => 'google', 'found' => true],
    ]]);
});

it('logs a failed lookup with its reason', function () {
    $geocoder = new CachedGeocoder(new FakeAdapter([fn () => throw GeocodingFailedException::httpStatus(503)]));

    expect(fn () => $geocoder->geocode('Berlin'))->toThrow(GeocodingFailedException::class);

    expect(loggedRecords())->toBe([[
        'level_name' => 'WARNING',
        'message' => 'The geocoding lookup failed.',
        'context' => ['source' => 'google', 'reason' => 'http_503'],
    ]]);
});

it('logs a lock timeout', function () {
    config(['geocode-cache.lock.wait' => 1]);
    Sleep::fake(syncWithCarbon: true);
    Cache::lock('geocode-cache:'.GeocodeQuery::make('Berlin')->hash('google'), 60)->get();

    expect(fn () => (new CachedGeocoder(FakeAdapter::returning()))->geocode('Berlin'))->toThrow(GeocodingFailedException::class);

    expect(loggedRecords())->toHaveCount(1)
        ->and(loggedRecords()[0]['level_name'])->toBe('WARNING')
        ->and(loggedRecords()[0]['message'])->toContain('gave up waiting for the lock');
});

it('logs the run of the prune command', function () {
    GeocodeCacheEntry::query()->create([
        'query_hash' => hash('sha256', 'a'),
        'source' => 'google',
        'latitude' => 1.0,
        'longitude' => 2.0,
        'fetched_at' => now()->subDays(30),
        'expires_at' => now()->subDay(),
        'last_requested_at' => now()->subDays(30),
    ]);

    $this->artisan('geocode-cache:prune')->assertSuccessful();

    expect(loggedRecords())->toBe([[
        'level_name' => 'INFO',
        'message' => 'Expired geocode cache entries were deleted.',
        'context' => ['deleted' => 1, 'statistics' => 0],
    ]]);
});

it('logs the run of the refresh command', function () {
    config(['geocode-cache.refresh.enabled' => true, 'geocode-cache.refresh.store_query' => true, 'geocode-cache.refresh.requested_within_days' => 30, 'geocode-cache.driver' => 'fake']);
    $adapter = new FakeAdapter([new Coordinates(1.0, 2.0)]);
    GeocodeCache::extend('fake', fn () => $adapter);
    GeocodeCache::geocode('Berlin');
    $this->travel(27)->days();
    $adapter->queue(new Coordinates(3.0, 4.0));

    $this->artisan('geocode-cache:refresh')->assertSuccessful();

    $records = loggedRecords();
    expect(end($records))->toBe([
        'level_name' => 'INFO',
        'message' => 'The geocode cache refresh finished.',
        'context' => ['source' => 'google', 'refreshed' => 1, 'copied' => 0, 'without_result' => 0, 'skipped' => 0, 'failed' => 0, 'by_place_id' => 0],
    ]);
});

it('never writes the searched text, coordinates, place IDs or hashes', function () {
    $query = GeocodeQuery::make('Secret Street 1, Berlin', 'DE');
    $geocoder = new CachedGeocoder(new FakeAdapter([
        new Coordinates(52.5219184, 13.4132147, 'place-secret'),
        fn () => throw GeocodingFailedException::connection(),
    ]));

    $geocoder->geocode($query);
    $this->travel(30)->days();
    expect(fn () => $geocoder->geocode($query))->toThrow(GeocodingFailedException::class);

    $log = (string) json_encode(loggedRecords());

    expect($log)->not->toContain('secret')
        ->not->toContain('Secret')
        ->not->toContain('52.52')
        ->not->toContain('13.41')
        ->not->toContain($query->hash('google'))
        ->not->toContain('test-key');
});

it('does not break a lookup when the log channel does not exist', function () {
    config(['geocode-cache.logging.channel' => 'missing']);

    expect((new CachedGeocoder(FakeAdapter::returning()))->geocode('Berlin'))->not->toBeNull();
    GeocodeLog::write('info', 'Still fine.');
});

it('shows the log channel in the about command', function () {
    $this->artisan('about', ['--only' => 'geocode_cache'])->expectsOutputToContain('geocode')->assertSuccessful();

    config(['geocode-cache.logging.channel' => null]);
    $this->artisan('about', ['--only' => 'geocode_cache'])->expectsOutputToContain('OFF')->assertSuccessful();
});

it('counts the place ID lookups of a refresh in the log', function () {
    config(['geocode-cache.refresh.enabled' => true, 'geocode-cache.refresh.store_query' => false, 'geocode-cache.refresh.requested_within_days' => 30, 'geocode-cache.driver' => 'places']);
    $adapter = new FakePlaceAdapter([new Coordinates(1.0, 2.0, 'p1')]);
    GeocodeCache::extend('places', fn () => $adapter);
    GeocodeCache::geocode('Berlin');
    $this->travel(27)->days();
    $adapter->queue(new Coordinates(3.0, 4.0, 'p1'));

    $this->artisan('geocode-cache:refresh')->assertSuccessful();

    $records = loggedRecords();
    expect(end($records)['context'])->toMatchArray(['refreshed' => 1, 'by_place_id' => 1]);
});

it('counts a failed place ID lookup of a refresh as a call in the log', function () {
    config(['geocode-cache.refresh.enabled' => true, 'geocode-cache.refresh.store_query' => false, 'geocode-cache.refresh.requested_within_days' => 30, 'geocode-cache.driver' => 'places']);
    $adapter = new FakePlaceAdapter([new Coordinates(1.0, 2.0, 'p1')]);
    GeocodeCache::extend('places', fn () => $adapter);
    GeocodeCache::geocode('Berlin');
    $this->travel(27)->days();
    $adapter->queue(fn () => throw GeocodingFailedException::connection());

    $this->artisan('geocode-cache:refresh')->assertFailed();

    $records = loggedRecords();
    expect(end($records)['context'])->toMatchArray(['failed' => 1, 'by_place_id' => 1]);
});
