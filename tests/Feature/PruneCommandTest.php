<?php

declare(strict_types=1);

use Taldres\GeocodeCache\Models\GeocodeCacheEntry;

beforeEach(function () {
    $this->travelTo('2026-10-17 12:00:00');
});

function cacheEntry(string $hash, DateTimeInterface $expiresAt): GeocodeCacheEntry
{
    return GeocodeCacheEntry::query()->create([
        'query_hash' => hash('sha256', $hash),
        'source' => 'google',
        'latitude' => 52.52,
        'longitude' => 13.405,
        'fetched_at' => now()->subDays(29),
        'expires_at' => $expiresAt,
        'last_requested_at' => now()->subDays(29),
    ]);
}

it('deletes the expired entries and keeps the valid ones', function () {
    cacheEntry('a', now()->subDay());
    cacheEntry('b', now()->subSecond());
    cacheEntry('c', now()->addSecond());
    cacheEntry('d', now()->addDays(10));

    $this->artisan('geocode-cache:prune')
        ->expectsOutputToContain('2 expired geocode cache entries deleted.')
        ->assertSuccessful();

    expect(GeocodeCacheEntry::query()->pluck('query_hash')->sort()->values()->all())
        ->toBe(collect(['c', 'd'])->map(fn (string $hash) => hash('sha256', $hash))->sort()->values()->all());
});

it('treats an entry as expired from its expires_at on', function () {
    cacheEntry('a', now());

    $this->artisan('geocode-cache:prune')->assertSuccessful();

    $this->assertDatabaseCount('geocode_cache', 0);
});

it('deletes the expired entries of a large table in chunks', function () {
    foreach (range(1, 25) as $number) {
        cacheEntry("x{$number}", now()->subMinute());
    }

    expect((new GeocodeCacheEntry)->pruneAll(10))->toBe(25);
    $this->assertDatabaseCount('geocode_cache', 0);
});

it('deletes nothing with --pretend', function () {
    cacheEntry('a', now()->subDay());
    cacheEntry('b', now()->addDay());

    $this->artisan('geocode-cache:prune --pretend')
        ->expectsOutputToContain('1 expired geocode cache entries would be deleted.')
        ->assertSuccessful();

    $this->assertDatabaseCount('geocode_cache', 2);
});

it('reports an empty table', function () {
    $this->artisan('geocode-cache:prune')
        ->expectsOutputToContain('0 expired geocode cache entries deleted.')
        ->assertSuccessful();
});
