<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Schema;
use Taldres\GeocodeCache\CachedGeocoder;
use Taldres\GeocodeCache\Data\Coordinates;
use Taldres\GeocodeCache\Data\GeocodeQuery;
use Taldres\GeocodeCache\Enums\RefreshOutcome;
use Taldres\GeocodeCache\Exceptions\GeocodingFailedException;
use Taldres\GeocodeCache\Models\GeocodeCacheStatistic;
use Taldres\GeocodeCache\Tests\Fixtures\FakeAdapter;

beforeEach(function () {
    $this->travelTo('2026-10-17 12:00:00');
    config(['geocode-cache.statistics.enabled' => true]);
});

function hashOf(string $search): string
{
    return GeocodeQuery::make($search)->hash('google');
}

/**
 * @return list<array{date: string, hits: int, lookups: int, refreshes: int}>
 */
function counters(string $search): array
{
    return GeocodeCacheStatistic::query()
        ->where('query_hash', hashOf($search))
        ->orderBy('date')
        ->get()
        ->map(fn (GeocodeCacheStatistic $row) => [
            'date' => $row->date,
            'hits' => $row->hits,
            'lookups' => $row->lookups,
            'refreshes' => $row->refreshes,
        ])
        ->all();
}

it('counts nothing unless the statistics are on', function () {
    config(['geocode-cache.statistics.enabled' => false]);
    $geocoder = new CachedGeocoder(FakeAdapter::returning());

    $geocoder->geocode('Berlin');
    $geocoder->geocode('Berlin');

    $this->assertDatabaseCount('geocode_cache_statistics', 0);
});

it('counts a lookup and the hits that follow', function () {
    $geocoder = new CachedGeocoder(FakeAdapter::returning());

    $geocoder->geocode('Berlin');
    $geocoder->geocode('Berlin');
    $geocoder->geocode('Berlin');

    expect(counters('Berlin'))->toBe([['date' => '2026-10-17', 'hits' => 2, 'lookups' => 1, 'refreshes' => 0]]);
});

it('keeps one row per hash and day', function () {
    $geocoder = new CachedGeocoder(new FakeAdapter([new Coordinates(1.0, 2.0), new Coordinates(3.0, 4.0)]));

    $geocoder->geocode('Berlin');
    $geocoder->geocode('Hamburg');
    $this->travel(1)->days();
    $geocoder->geocode('Berlin');

    expect(counters('Berlin'))->toBe([
        ['date' => '2026-10-17', 'hits' => 0, 'lookups' => 1, 'refreshes' => 0],
        ['date' => '2026-10-18', 'hits' => 1, 'lookups' => 0, 'refreshes' => 0],
    ])->and(counters('Hamburg'))->toHaveCount(1);
    $this->assertDatabaseCount('geocode_cache_statistics', 3);
});

it('counts a request by the UTC day', function () {
    config(['app.timezone' => 'Europe/Berlin']);
    $this->travelTo(Date::parse('2026-10-18 01:30:00', 'Europe/Berlin'));

    (new CachedGeocoder(FakeAdapter::returning()))->geocode('Berlin');

    expect(counters('Berlin')[0]['date'])->toBe('2026-10-17');
});

it('counts a lookup that found nothing or failed, because the service was asked', function () {
    $geocoder = new CachedGeocoder(new FakeAdapter([null, fn () => throw GeocodingFailedException::connection()]));

    $geocoder->geocode('Nowhere');
    expect(fn () => $geocoder->geocode('Nowhere'))->toThrow(GeocodingFailedException::class);

    expect(counters('Nowhere'))->toBe([['date' => '2026-10-17', 'hits' => 0, 'lookups' => 2, 'refreshes' => 0]]);
});

it('counts a refresh apart from the requests', function () {
    $geocoder = new CachedGeocoder(new FakeAdapter([new Coordinates(1.0, 2.0), new Coordinates(3.0, 4.0)]));
    $geocoder->geocode('Berlin');

    expect($geocoder->refresh(GeocodeQuery::make('Berlin')))->toBe(RefreshOutcome::Refreshed)
        ->and(counters('Berlin'))->toBe([['date' => '2026-10-17', 'hits' => 0, 'lookups' => 1, 'refreshes' => 1]]);
});

it('does not count a request that could not get the entry or the lock', function () {
    $geocoder = new CachedGeocoder(FakeAdapter::returning());

    expect($geocoder->refresh(GeocodeQuery::make('Berlin')))->toBe(RefreshOutcome::Skipped);

    $this->assertDatabaseCount('geocode_cache_statistics', 0);
});

it('does not break a lookup when the counters cannot be written', function () {
    // No DDL here: on MySQL and MariaDB it commits the transaction of the test and voids its savepoints.
    config(['geocode-cache.statistics.table' => 'missing_statistics']);

    expect((new CachedGeocoder(FakeAdapter::returning()))->geocode('Berlin'))->not->toBeNull();
});

it('refuses an unknown counter', function () {
    GeocodeCacheStatistic::record(hashOf('Berlin'), 'visits');
})->throws(InvalidArgumentException::class, 'Unknown counter');

it('stores no query, coordinates or place ID in the statistics', function () {
    (new CachedGeocoder(FakeAdapter::returning(52.5219184, 13.4132147, 'place-secret')))->geocode('Secret Street 1');

    expect(Schema::getColumnListing('geocode_cache_statistics'))->toBe(['id', 'query_hash', 'date', 'hits', 'lookups', 'refreshes']);
});

it('deletes the statistics that are older than the retention when pruning', function () {
    config(['geocode-cache.statistics.retention_days' => 90]);
    foreach (['2026-07-19' => 'kept', '2026-07-18' => 'deleted', '2026-01-01' => 'deleted'] as $date => $ignored) {
        GeocodeCacheStatistic::query()->create(['query_hash' => hashOf($date), 'date' => $date, 'hits' => 1, 'lookups' => 0, 'refreshes' => 0]);
    }

    $this->artisan('geocode-cache:prune --pretend')
        ->expectsOutputToContain('2 old geocode cache statistics rows would be deleted.')
        ->assertSuccessful();
    $this->assertDatabaseCount('geocode_cache_statistics', 3);

    $this->artisan('geocode-cache:prune')
        ->expectsOutputToContain('2 old geocode cache statistics rows deleted.')
        ->assertSuccessful();

    expect(GeocodeCacheStatistic::query()->pluck('date')->all())->toBe(['2026-07-19']);
});

it('refuses to prune with an invalid statistics retention', function () {
    config(['geocode-cache.statistics.retention_days' => 0]);

    $this->artisan('geocode-cache:prune')->expectsOutputToContain('retention_days')->assertFailed();
});

it('shows how often and how regularly hashes were requested', function () {
    $geocoder = new CachedGeocoder(new FakeAdapter([new Coordinates(1.0, 2.0), new Coordinates(3.0, 4.0)]));

    $geocoder->geocode('Berlin');
    $this->travel(2)->days();
    $geocoder->geocode('Berlin');
    $geocoder->geocode('Berlin');
    $geocoder->geocode('Hamburg');

    expect(Artisan::call('geocode-cache:statistics', ['--days' => 30]))->toBe(0);

    $output = Artisan::output();
    $rows = collect(explode("\n", $output))->filter(fn (string $line) => str_contains($line, substr(hashOf('Berlin'), 0, 12)));

    expect($rows)->toHaveCount(1)
        ->and($rows->first())->toContain('2 of 30')
        ->toContain('2026-10-17')
        ->toContain('2026-10-19')
        ->and($output)->toContain('2 of 2 hashes shown');
});

it('limits the statistics to the window and the number of hashes', function () {
    $geocoder = new CachedGeocoder(new FakeAdapter([new Coordinates(1.0, 2.0), new Coordinates(3.0, 4.0)]));
    $geocoder->geocode('Berlin');
    $this->travel(10)->days();
    $geocoder->geocode('Hamburg');

    $this->artisan('geocode-cache:statistics --days=5')
        ->expectsOutputToContain(substr(hashOf('Hamburg'), 0, 12))
        ->doesntExpectOutputToContain(substr(hashOf('Berlin'), 0, 12))
        ->assertSuccessful();

    $this->artisan('geocode-cache:statistics --days=30 --limit=1')
        ->expectsOutputToContain('1 of 2 hashes shown')
        ->assertSuccessful();
});

it('reports when nothing was counted', function () {
    $this->artisan('geocode-cache:statistics')->expectsOutputToContain('No requests were counted in the last 30 days.')->assertSuccessful();
});

it('warns when the statistics are off', function () {
    config(['geocode-cache.statistics.enabled' => false]);

    $this->artisan('geocode-cache:statistics')->expectsOutputToContain('The statistics are off.')->assertSuccessful();
});

it('refuses invalid options', function (string $options) {
    $this->artisan("geocode-cache:statistics {$options}")->expectsOutputToContain('must be a whole number')->assertFailed();
})->with(['--days=0', '--days=99999', '--days=abc', '--limit=0', '--limit=1001', '--limit=-3']);
