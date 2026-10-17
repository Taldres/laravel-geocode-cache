---
name: laravel-geocode-cache-development
description: Geocode addresses, places and postal codes with taldres/laravel-geocode-cache, which caches coordinates in a database table with a hard expiry. Covers the GeocodeCache facade, GeocodeQuery and Bounds, GeocodeResult, the google driver, place ID lookups and keeping coordinates for models, custom adapters, the retention in ttl_days, the geocode-cache:prune command and its scheduler entry, the optional geocode-cache:refresh command, locks against duplicate lookups, error handling with GeocodingFailedException and testing with Http::fake().
---

# Laravel Geocode Cache Development

## When to use this skill

Use it when an application needs coordinates for an address, a place or a postal code and `taldres/laravel-geocode-cache`
is installed. The package is a cache with retention rules, not a store for geodata: every entry expires, and expired
entries are deleted. Google allows the temporary storage of coordinates for up to 30 consecutive calendar days. The
package does not guarantee compliance with Google's terms. Attribution, map display rules and the contract that applies
are the application's job.

## Setup

1. Publish the config and the migration, set the table name in `config/geocode-cache.php` first if the default
   `geocode_cache` does not fit, then migrate:

    ```bash
    php artisan vendor:publish --tag=geocode-cache
    php artisan migrate
    ```

2. Set the API key in `.env`. The `google` driver uses it:

    ```
    GOOGLE_MAPS_GEOCODING_API_KEY=...
    ```

3. Schedule the pruning in `routes/console.php`. This is required: an expired entry is never returned, but only the
   command removes entries nobody asks for again.

    ```php
    use Illuminate\Support\Facades\Schedule;

    Schedule::command('geocode-cache:prune')->hourly()->onOneServer()->withoutOverlapping();
    ```

`php artisan about --only=geocode_cache` shows the driver, the table, the retention and whether the prune command is
scheduled. Run it to check the setup.

## Looking Coordinates Up

```php
use Taldres\GeocodeCache\Data\Bounds;
use Taldres\GeocodeCache\Data\GeocodeQuery;
use Taldres\GeocodeCache\Facades\GeocodeCache;

$result = GeocodeCache::geocode(GeocodeQuery::make('Alexanderplatz 1, Berlin', country: 'DE', language: 'de'));

$result?->latitude;   // float
$result?->longitude;  // float
$result?->placeId;    // ?string
$result?->expiresAt;  // CarbonInterface: the entry may not be used after this moment
```

- It returns `null` when the service found nothing. Nothing is stored for such a query, so it asks again next time.
- It throws `Taldres\GeocodeCache\Exceptions\GeocodingFailedException` when the service could not be reached or
  reported an error, or when another process holds the lock for the same query for longer than `lock.wait`. A failure
  never returns or extends old data.
- Pass postal codes as strings, `'01067'`, never as integers, and give a country for postal codes and place names.
  The result is a representative point of the area, not a street address.
- The search text is lower-cased and whitespace-squished, and the country, language, region and bounds are part of the
  cache key, so equivalent queries share an entry.
- `GeocodeCache::driver('google')->geocode(...)` picks a driver. `google` is the driver that ships with the package,
  other services are added as custom adapters.

## Coordinates for Models

A model (venue, company, property) keeps its own address and optionally the Google place ID, never latitude and
longitude, and asks the cache for the coordinates when it needs them. Do not add a relation or a foreign key to the cache
table and do not store the `query_hash`: entries expire and are deleted.

```php
use Taldres\GeocodeCache\Data\PlaceIdQuery;

$result = GeocodeCache::geocode(PlaceIdQuery::make($venue->google_place_id)) // null if Google does not know the place anymore
    ?? GeocodeCache::geocode(GeocodeQuery::make($venue->full_address, country: 'DE'));

$result?->placeId; // remember it on the model yourself, the package never writes to your models
```

- A place ID is opaque: it is not lower-cased or changed. The `google` adapter can look one up, and a custom adapter
  can if it implements `SupportsPlaceIdLookup`. For any other adapter the call throws a `LogicException` before the cache
  is read.
- Wrap the lookup in a method that is named so the call is visible. Do not call it from accessors that are serialised or
  in loops over many models.
- `store_place_id` has to be `true` (the default), otherwise there is no place ID to remember.

## What Is Stored

Only the key hash, the source, latitude, longitude (rounded to `GEOCODE_CACHE_PRECISION` decimals, up to the 7 that
Google delivers), the optional place ID (`GEOCODE_CACHE_STORE_PLACE_ID`), and the
fetch and expiry times. The formatted address and the address components of the response are never stored. The default
retention is 29 days (`GEOCODE_CACHE_TTL_DAYS`, or `source_ttl_days` per source, 1 to 30 for Google). It counts from the fetch. Reading an entry does
not extend it, only a new successful lookup starts a new period.

Copies elsewhere are the application's responsibility: do not log results, do not put them in queue payloads or other
caches that outlive the period, and plan for backups.

## Refreshing Entries That Are Still Used

Off by default. With `GEOCODE_CACHE_REFRESH_ENABLED=true` the package also stores the time of the last request and
renews entries through their place ID, so it needs `store_place_id` (the default) and an adapter that can look a place ID
up, such as `google`. With `GEOCODE_CACHE_REFRESH_STORE_QUERY=true` it stores the search text of each query too,
encrypted with the application key, and asks for the address again. Without it the table holds no searched text.
Schedule the command next to the prune command:

```php
Schedule::command('geocode-cache:refresh')->daily()->onOneServer()->withoutOverlapping();
```

It fetches entries again that expire within `refresh.expiring_within_days` and were requested within
`refresh.requested_within_days`. Entries nobody requests anymore are not renewed and expire. Due entries with the same `place_id` share one service call: the
result is copied to the other due entries of that place. A run makes at most
`refresh.limit` service calls (`--limit` overrides it), and a failed call changes nothing. A place ID that is not valid anymore leaves
the entry alone and it expires. Changing the application key makes stored search texts unreadable, and such entries are
refreshed through their place ID or skipped until they expire.

## Request Statistics

Off by default. `GEOCODE_CACHE_STATISTICS_ENABLED=true` counts, per query hash and UTC day, the `hits` (answered from the
cache), `lookups` (the service was asked) and `refreshes`. `php artisan geocode-cache:statistics --days=30 --limit=20`
shows which hashes are requested how regularly (active days). The rows hold no query and no coordinates, and the prune
command deletes rows older than `GEOCODE_CACHE_STATISTICS_RETENTION_DAYS` (90). Query the table with `->utc()` dates.

## Logging

Off by default. `GEOCODE_CACHE_LOG_CHANNEL=geocode` writes failed lookups, lock timeouts and the runs of the commands to
that log channel. It never contains the searched text, the key hash, coordinates, place IDs or the API key, so do not add
them when you extend the package.

## Custom Adapters

Implement `Taldres\GeocodeCache\Contracts\Adapter` (`source()`, `maximumPrecision()`, `maximumRetentionDays()` and `geocode(GeocodeQuery): ?Coordinates`, throwing
`GeocodingFailedException` on failure) and register it. The manager wraps it in the cache:

```php
GeocodeCache::extend('mapbox', fn () => new MapboxAdapter(config('services.mapbox.token')));
```

An adapter also reports `maximumRetentionDays()` (`null` for no limit). The retention of a source other than `google`
can be set with `source_ttl_days` (`['mapbox' => 365]`) and may exceed 30 days if its terms allow it. Check the terms of
that provider first. Adapter, driver and source are different things: the driver is the configured name, the source is
the provider that is stored with every entry.

## Testing

Fake the HTTP call of the Google adapter. Use a fresh database per test:

```php
Http::fake(['maps.googleapis.com/*' => Http::response([
    'status' => 'OK',
    'results' => [['geometry' => ['location' => ['lat' => 52.5, 'lng' => 13.4]], 'place_id' => 'abc']],
])]);
```

Use `$this->travel(30)->days()` to test expiry. Bind a custom adapter with `GeocodeCache::extend()` to avoid HTTP
altogether.

## Anti-Patterns

- Not scheduling `geocode-cache:prune`.
- Raising `ttl_days` above 30 for Google results, or reading `geocode_cache` rows directly and ignoring `expires_at`.
- Storing the formatted address or other fields of a Google response next to the coordinates.
- Passing postal codes as integers.
- Adding `latitude` and `longitude` columns, or a relation to the cache table, to a model.
- Catching `GeocodingFailedException` and falling back to older coordinates that are stored elsewhere.
- Calling the Google API directly for something the cache can answer.
