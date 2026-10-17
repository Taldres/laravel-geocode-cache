<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Taldres\GeocodeCache\CachedGeocoder;
use Taldres\GeocodeCache\Models\GeocodeCacheEntry;
use Taldres\GeocodeCache\Tests\Fixtures\FakeAdapter;

it('stores the dates as UTC and returns them in the application timezone', function () {
    config(['app.timezone' => 'Europe/Berlin']);
    $this->travelTo(Date::parse('2026-07-01 12:00:00', 'Europe/Berlin'));

    $geocoder = new CachedGeocoder(FakeAdapter::returning());
    $geocoder->geocode('Berlin');
    $result = $geocoder->geocode('Berlin');

    expect(DB::table('geocode_cache')->value('fetched_at'))->toBe('2026-07-01 10:00:00')
        ->and(DB::table('geocode_cache')->value('expires_at'))->toBe('2026-07-30 10:00:00')
        ->and($result->fetchedAt->getTimezone()->getName())->toBe('Europe/Berlin')
        ->and(GeocodeCacheEntry::query()->firstOrFail()->expires_at->format('H:i'))->toBe('12:00');
});

it('keeps the end of the period when the timezone of the application changes', function () {
    config(['app.timezone' => 'Europe/Berlin']);
    $this->travelTo(Date::parse('2026-07-01 12:00:00', 'Europe/Berlin'));
    $geocoder = new CachedGeocoder(FakeAdapter::returning());
    $geocoder->geocode('Berlin');
    $expiresAt = GeocodeCacheEntry::query()->firstOrFail()->expires_at->getTimestamp();

    config(['app.timezone' => 'UTC']);

    expect(GeocodeCacheEntry::query()->firstOrFail()->expires_at->getTimestamp())->toBe($expiresAt);
});

it('prunes an entry in the repeated hour of a daylight saving change', function () {
    config(['app.timezone' => 'Europe/Berlin']);
    $previous = date_default_timezone_get();
    // Laravel sets the default timezone to app.timezone when it boots.
    date_default_timezone_set('Europe/Berlin');
    // 00:30 UTC on 25 October 2026 is 02:30 CEST. One hour later the clocks show 02:30 again, now CET.
    GeocodeCacheEntry::query()->create([
        'query_hash' => hash('sha256', 'a'),
        'source' => 'google',
        'latitude' => 1.0,
        'longitude' => 2.0,
        'fetched_at' => '2026-09-26 00:30:00',
        'expires_at' => '2026-10-25 00:30:00',
        'last_requested_at' => '2026-09-26 00:30:00',
    ]);
    $this->travelTo(Date::parse('2026-10-25 01:15:00', 'UTC'));

    $this->artisan('geocode-cache:prune')->expectsOutputToContain('1 expired')->assertSuccessful();

    $this->assertDatabaseCount('geocode_cache', 0);

    date_default_timezone_set($previous);
});
