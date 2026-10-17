# Custom Adapters

An adapter asks one geocoding service. See the [terms](introduction.md#terms) for adapter, driver and source. Implement `Taldres\GeocodeCache\Contracts\Adapter`:

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

Register it in the `boot` method of a service provider. The manager wraps the adapter in the cache, so you do not have
to deal with entries, expiry or locks:

```php
use Taldres\GeocodeCache\Facades\GeocodeCache;

GeocodeCache::extend('mapbox', fn () => new MapboxAdapter(config('services.mapbox.token')));
```

Use it with `GeocodeCache::driver('mapbox')`, or set `driver` in the configuration. A driver can have its own settings
under `drivers.mapbox`, for the credentials and the timeout.

Create failures with the factories of `GeocodingFailedException` (`connection()`, `httpStatus()`, `serviceStatus()`,
`malformedResponse()`), or pass a fixed `reason` code to its constructor. Never copy the message of a library exception into it.

Catch the exceptions of your HTTP client and translate them. If an adapter lets another exception escape, the cache
stores nothing, logs the failure with the reason `unexpected`, dispatches `GeocodeLookupFailed` and passes the exception
on unchanged. Its message may then hold the request URL, which the package cannot prevent.

## Place IDs

An adapter that also implements `Taldres\GeocodeCache\Contracts\SupportsPlaceIdLookup` can answer a
[`PlaceIdQuery`](usage.md#looking-a-place-up-by-its-id):

```php
public function geocodePlaceId(PlaceIdQuery $query): ?Coordinates
{
    // Return null if the service does not know the place (anymore), and throw a GeocodingFailedException if it failed.
}
```

Without it the adapter only answers address queries, and the cache refuses a place ID query for it with a
`UnsupportedQueryException` instead of searching for something else. The place ID of the `Coordinates` you return is stored and
handed to the application, which can remember it.

## The Source

`source()` is stored with every entry and is part of the cache key. Adapters that read from the same provider return the
same value and share their entries. An adapter for another provider needs its own value, and one that reads from Google
returns `google`.

## The Maximum Retention

`maximumRetentionDays()` is the most days the terms of the provider allow a result to be kept, counted from the fetch, or
`null` if they set no limit. The retention of the cache never exceeds it: a `ttl_days` or `source_ttl_days` value above
it is rejected with an `InvalidConfigurationException` before the service is called, and an entry that was stored for a
longer period is deleted instead of returned. Adapters with the same source must report the same value, because they
share their entries. The `google` adapter returns 30.

The retention itself comes from the configuration and belongs to the source, not to the driver, because drivers with
the same source share their entries. Set it for your source next to the default:

```php
'ttl_days' => 29,
'source_ttl_days' => ['mapbox' => 365],
```

Read the terms of the provider first.

## The Maximum Precision

`maximumPrecision()` is the most decimals of a coordinate the service delivers. The `precision` setting cannot exceed it,
and the cache table stores seven decimals at most, so a larger value is treated as 7.

## What the Adapter Returns

`Coordinates` holds the latitude, the longitude and an optional place ID. It is all the cache keeps, so the adapter
cannot leak other fields of a response into the table. Use `Coordinates::fromResponse()` to build it from untrusted
response values.

## Wrapping the Google Adapter

The adapters of the package are final. To change how the `google` driver behaves, wrap its adapter in your own one and
pass every call on. This one keeps the requests to Google below a limit per minute:

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

Register it under the name `google`. It replaces the built-in driver, so the `driver` setting, `GeocodeCache::driver('google')`
and `--driver=google` use it:

```php
use Taldres\GeocodeCache\Adapters\GoogleAdapter;
use Taldres\GeocodeCache\Facades\GeocodeCache;

GeocodeCache::extend('google', fn () => new RateLimitedGoogleAdapter(
    new GoogleAdapter(
        config()->string('geocode-cache.drivers.google.api_key'),
        timeout: config()->integer('geocode-cache.drivers.google.timeout'),
    ),
    perMinute: 50,
));
```

- Pass `source()`, `maximumPrecision()` and `maximumRetentionDays()` on unchanged. The wrapper then shares its entries
  with the plain adapter and keeps the 30 days of Google.
- Implement `SupportsPlaceIdLookup` as well. Without it the cache refuses place ID queries and `geocode-cache:refresh`
  cannot renew entries through their place ID.
- The cache only calls the adapter when it has no valid entry, so cache hits do not count against the limit.
- A `GeocodingFailedException` from the wrapper is handled like any failure of the service: nothing is stored, and an
  existing entry stays as it is. Its `reason` reaches the log and `GeocodeLookupFailed`, so use a fixed code, and never
  put the query into the message.
- The built-in driver rejects a `timeout` that is not shorter than `lock.seconds`. A driver of your own is not
  checked, so keep the timeout below the lifetime of the lock yourself.
