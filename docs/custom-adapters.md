# Custom Adapters

An adapter asks one geocoding service, see the [terms](introduction.md#terms). Implement
`Taldres\GeocodeCache\Contracts\Adapter`:

```php
use Taldres\GeocodeCache\Contracts\Adapter;
use Taldres\GeocodeCache\Data\Coordinates;
use Taldres\GeocodeCache\Data\GeocodeQuery;
use Taldres\GeocodeCache\Exceptions\GeocodingFailedException;

class MapboxAdapter implements Adapter
{
    public function __construct(private string $token) {}

    public function source(): string
    {
        return 'mapbox';
    }

    public function maximumPrecision(): int
    {
        return 6; // The most decimals the service delivers, 7 at most.
    }

    public function maximumRetentionDays(): ?int
    {
        return null; // The most days the terms of the service allow results to be kept, null for no limit.
    }

    public function geocode(GeocodeQuery $query): ?Coordinates
    {
        // Return null if the service found nothing and throw a GeocodingFailedException if it failed.
        // Never put the request URL into an exception message, it contains your token and the searched address.
    }
}
```

Register it in the `boot` method of a service provider. The manager wraps the adapter in the cache, so you do not deal
with entries, expiry or locks:

```php
use Taldres\GeocodeCache\Facades\GeocodeCache;

GeocodeCache::extend('mapbox', fn () => new MapboxAdapter(config('services.mapbox.token')));
```

Use it with `GeocodeCache::driver('mapbox')`, or set `driver` in the configuration. Credentials and timeouts can live
under `drivers.mapbox`.

## Failures

Create failures with the factories of `GeocodingFailedException` (`connection()`, `httpStatus()`, `serviceStatus()`,
`malformedResponse()`), or pass a fixed `reason` code to its constructor. Never copy the message of a library exception
into it. Catch the exceptions of your HTTP client and translate them. If another exception escapes, the cache stores
nothing, logs the failure with the reason `unexpected`, dispatches `GeocodeLookupFailed` and passes the exception on
unchanged. Its message may then hold the request URL, which the package cannot prevent.

## What the Adapter Reports

- **`source()`** is stored with every entry and is part of the cache key. Adapters that read from the same provider
  return the same value and share their entries. One that reads from Google returns `google`.
- **`maximumRetentionDays()`** is the most days the terms of the provider allow, or `null` for no limit. A `ttl_days` or
  `source_ttl_days` above it is rejected, and an entry stored for longer is deleted instead of returned. Adapters with
  the same source must report the same value. The `google` adapter returns 29.
- **`maximumPrecision()`** is the most decimals the service delivers. The `precision` setting cannot exceed it, and the
  table stores seven at most.
- **`Coordinates`** holds the latitude, the longitude and an optional place ID. It is all the cache keeps, so no other
  field of a response can leak into the table. `Coordinates::fromResponse()` builds it from untrusted response values.

The retention comes from the configuration and belongs to the source. Reading and following the terms of the provider is
your responsibility, the package only enforces the maximum that the adapter reports:

```php
'ttl_days' => 29,
'source_ttl_days' => ['mapbox' => 365],
```

## Place IDs

An adapter that also implements `Taldres\GeocodeCache\Contracts\SupportsPlaceIdLookup` can answer a
[`PlaceIdQuery`](usage.md#looking-a-place-up-by-its-id):

```php
public function geocodePlaceId(PlaceIdQuery $query): ?Coordinates
{
    // Return null if the service does not know the place (anymore), and throw a GeocodingFailedException if it failed.
}
```

Without it, the cache refuses a place ID query for the adapter with an `UnsupportedQueryException`. The place ID of the
`Coordinates` you return is stored and handed to the application.

## Wrapping the Google Adapter

The adapters of the package are final. To change how the `google` driver behaves, wrap its adapter and pass every call
on. This one keeps the requests to Google below a limit per minute:

```php
use Illuminate\Support\Facades\RateLimiter;
use Taldres\GeocodeCache\Adapters\GoogleAdapter;
use Taldres\GeocodeCache\Contracts\Adapter;
use Taldres\GeocodeCache\Contracts\SupportsPlaceIdLookup;
use Taldres\GeocodeCache\Data\Coordinates;
use Taldres\GeocodeCache\Data\GeocodeQuery;
use Taldres\GeocodeCache\Data\PlaceIdQuery;
use Taldres\GeocodeCache\Exceptions\GeocodingFailedException;

class RateLimitedGoogleAdapter implements Adapter, SupportsPlaceIdLookup
{
    public function __construct(private GoogleAdapter $google, private int $perMinute) {}

    public function source(): string
    {
        return $this->google->source();
    }

    public function maximumPrecision(): int
    {
        return $this->google->maximumPrecision();
    }

    public function maximumRetentionDays(): ?int
    {
        return $this->google->maximumRetentionDays();
    }

    public function geocode(GeocodeQuery $query): ?Coordinates
    {
        $this->throttle();

        return $this->google->geocode($query);
    }

    public function geocodePlaceId(PlaceIdQuery $query): ?Coordinates
    {
        $this->throttle();

        return $this->google->geocodePlaceId($query);
    }

    private function throttle(): void
    {
        if (RateLimiter::tooManyAttempts('geocode-google', $this->perMinute)) {
            throw new GeocodingFailedException('The request limit for the geocoding service is reached.', 'rate_limited');
        }

        RateLimiter::increment('geocode-google');
    }
}
```

Register it under the name `google`. It replaces the built-in driver, so the `driver` setting,
`GeocodeCache::driver('google')` and `--driver=google` use it:

```php
GeocodeCache::extend('google', fn () => new RateLimitedGoogleAdapter(
    new GoogleAdapter(
        config()->string('geocode-cache.drivers.google.api_key'),
        timeout: config()->integer('geocode-cache.drivers.google.timeout'),
    ),
    perMinute: 50,
));
```

- Pass `source()`, `maximumPrecision()` and `maximumRetentionDays()` on unchanged, so the wrapper shares its entries
  with the plain adapter and keeps the 29 days of Google.
- Implement `SupportsPlaceIdLookup` as well. Without it the cache refuses place ID queries and the refresh cannot renew
  entries through their place ID.
- The cache only calls the adapter when it has no valid entry, so cache hits do not count against the limit.
- Use a fixed `reason` code for your failures, and never put the query into the message.
- A driver of your own is not checked against `lock.seconds`, so keep its timeout below the lifetime of the lock.
