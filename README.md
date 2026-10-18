<div align="center">
    <h1>Laravel Geocode Cache</h1>
</div>

<p align="center">
    <a href="https://packagist.org/packages/taldres/laravel-geocode-cache"><img src="https://img.shields.io/packagist/v/taldres/laravel-geocode-cache.svg?style=flat-square" alt="Packagist"></a>
    <a href="https://github.com/Taldres/laravel-geocode-cache/actions"><img alt="Tests" src="https://img.shields.io/github/actions/workflow/status/Taldres/laravel-geocode-cache/tests.yml?branch=main&label=Tests&style=flat-square"></a>
</p>

A geocoding cache for Laravel with retention limits and scheduled deletion. It turns addresses, places and postal codes
into coordinates, stores the result in your database for a limited time and manages the life cycle of the stored data:
hard expiry, real deletion, lookups only when needed and no duplicate requests in parallel.

> [!WARNING]
> The package is below version 1.0. Until then, a minor version (`0.x`) may contain breaking changes. Constrain the
> version in your `composer.json` to a minor, for example `^0.1`, and read the release notes before you update.

```php
use Taldres\GeocodeCache\Data\GeocodeQuery;
use Taldres\GeocodeCache\Facades\GeocodeCache;

$result = GeocodeCache::geocode(GeocodeQuery::make('01067', country: 'DE'));

$result?->latitude;   // 51.0504088
$result?->expiresAt;  // the entry may not be used after this moment
```

## Why Use This Package

Geocoding results are not yours to keep forever. Google allows the temporary storage of coordinates for up to 30
consecutive calendar days, and a plain cache or a `latitude` column in your table ignores that. Calling the API for every
request costs money and time, though. This package sits in between:

- **Fewer API calls.** Valid entries come from your database, and a lock per query stops parallel requests from calling
  the service twice.
- **Retention built in.** Every entry has a hard expiry that reading never extends. An expired entry is never returned,
  not even when the service is down, and a scheduled command really deletes it.
- **Less data.** Only the coordinates and the optional place ID are stored, never the formatted address or other fields
  of a response. Logs, events and exception messages never hold the searched text or coordinates.
- **Models stay current.** A model keeps its address and the Google place ID and asks the cache for the coordinates. An
  optional refresh renews entries that are still in use before they expire.
- **Failures stay harmless.** A failed or empty lookup never changes a stored entry.

## Features

- Google Geocoding API as the data source
- Stores only the coordinates and the optional place ID, never the whole response
- A default retention of 29 days, counted from the fetch. Reading never extends it
- A hard expiry check on every access: expired coordinates are never returned, not even when the service is down
- `geocode-cache:prune` deletes expired entries that nobody asks for again
- A lock per query stops parallel requests from calling the service twice
- Failures and empty results are kept apart from coordinates
- An optional refresh renews entries that are still in use before they expire, through their place ID without storing the searched text, and skips queries nobody asks for anymore
- Optional request statistics: how often and how regularly each query hash is requested
- An optional, data-sparing log of failures and command runs
- Lookups by Google place ID, so a model can keep the ID and ask the cache for the coordinates
- Custom adapters for other services
- A Laravel Boost skill for coding agents

## Installation

```bash
composer require taldres/laravel-geocode-cache
php artisan vendor:publish --tag=geocode-cache
php artisan migrate
```

Add your API key to `.env`:

```
GOOGLE_MAPS_GEOCODING_API_KEY=your-key
```

Schedule the pruning in `routes/console.php`. **This step is required**, because only the command deletes expired
entries that nobody asks for again:

```php
use Illuminate\Support\Facades\Schedule;

Schedule::command('geocode-cache:prune')->hourly()->onOneServer()->withoutOverlapping();
```

Check the setup with `php artisan about --only=geocode_cache`.

## Usage

```php
// Postal codes are strings, so leading zeros survive. Give a country to avoid ambiguity.
$result = GeocodeCache::geocode(GeocodeQuery::make('01067', country: 'DE'));

// An address with more parameters. All of them are part of the cache key.
$result = GeocodeCache::geocode(GeocodeQuery::make(
    'Alexanderplatz 1, Berlin',
    country: 'DE',
    language: 'de',
));

if ($result === null) {
    // The service found nothing. Nothing was stored.
}
```

A failed lookup throws a `Taldres\GeocodeCache\Exceptions\GeocodingFailedException`, and a lookup that waited in vain for a
parallel request of the same query a `GeocodingLockTimeoutException`. The exceptions the package defines implement
`GeocodeCacheException`, see [Failures, Empty Results and Locks](docs/failures-and-locks.md). The `GeocodeServiceCalled`
and `GeocodeLookupFailed` events tell your application about calls of the service, see
[Events and Logging](docs/events-and-logging.md).

Keeping the coordinates of a venue or a company? See [Coordinates for Your Models](docs/models.md).

## Documentation

The full documentation is in [docs](docs/README.md).

## Limits

The package is a geocoding cache with retention and deletion rules. **You are responsible for reading and following the
terms of Google and of every other geocoding provider you use**: retention, attribution, display rules and map usage. The
package cannot guarantee that your use complies with them, and it cannot clean copies outside its own table. See
[Google Terms](docs/google-terms.md) and [Retention and Deletion](docs/retention-and-deletion.md).

## Changelog

Please see [CHANGELOG](CHANGELOG.md) for more information on what has changed recently.

## Contributing

Please review the [contributing guide](.github/CONTRIBUTING.md) to get started.

## Security Vulnerabilities

Please review [our security policy](.github/SECURITY.md) on how to report security vulnerabilities.

## Credits

- [Dennis Petersmann](https://github.com/Taldres)

## License

Laravel Geocode Cache is open-sourced software licensed under the [MIT license](LICENSE).
