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
