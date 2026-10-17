# Looking Coordinates Up

## The Query

A `GeocodeQuery` holds everything that influences the result:

```php
use Taldres\GeocodeCache\Data\Bounds;
use Taldres\GeocodeCache\Data\GeocodeQuery;

GeocodeQuery::make(
    search: 'Springfield',
    country: 'US',                              // ISO 3166-1 alpha-2, restricts the result
    language: 'en',                             // language of the result
    region: 'us',                               // region bias as a ccTLD
    bounds: new Bounds(37.0, -90.0, 40.0, -85.0), // south-west and north-east corner, biases the result
);
```

All of it, plus the data source, is part of the cache key. The search text is trimmed, squished and lower-cased, and
the country, language and region are normalised, so `'Berlin '` and `'berlin'` share an entry while `'Springfield'` in
the US and in Canada do not. The facade also accepts a plain string for a query without parameters.

## Postal Codes and Places

Pass a postal code as a string, so leading zeros survive, and give a country to avoid ambiguity:

```php
GeocodeCache::geocode(GeocodeQuery::make('01067', country: 'DE'));
```

A postal code or a place name returns a representative point of the area, not the point of a street address.

## Looking a Place Up by Its ID

A result carries the Google place ID (`placeId`) of the place it found. The ID can be looked up again later, without the
address:

```php
use Taldres\GeocodeCache\Data\PlaceIdQuery;

$result = GeocodeCache::geocode(PlaceIdQuery::make('ChIJ...'));
```

It goes through the same cache, with the same expiry and the same locks. The cache key is the data source and the ID,
exactly as given: a place ID is opaque, so it is neither lower-cased nor changed in any other way, and language, region
and bounds do not apply. It returns `null` if the service does not know the place (anymore), so you can search for the
address instead. [Coordinates for Your Models](models.md) shows how.

The `google` adapter can look a place ID up. A [custom adapter](custom-adapters.md#place-ids) can as well, if it
implements the optional interface. For an adapter that cannot, the call throws a `LogicException` before the cache is
read, so the answer does not depend on what happens to be stored. `GeocodeCache::driver()->supports($query)` tells
beforehand.

## The Result

`geocode()` returns a `Taldres\GeocodeCache\Data\GeocodeResult` or `null` if the service found nothing:

| Property | Meaning |
| --- | --- |
| `latitude`, `longitude` | The coordinates, rounded to `precision` decimals (7 at most). |
| `placeId` | The Google place ID, or `null` if it is not available or `store_place_id` is off. |
| `source` | The data provider, for example `google`. |
| `fetchedAt` | When the data was fetched from the service. |
| `expiresAt` | The moment from which the entry is expired and may not be used. |

A result from the cache and a fresh one look the same. Do not keep `latitude` and `longitude` beyond `expiresAt`
somewhere else, for example in your own models. That would move the data out of the lifecycle of the cache.

## Drivers

```php
GeocodeCache::geocode($query);                       // the default driver
GeocodeCache::driver('google')->geocode($query);     // a specific driver
```

`google` is the driver that ships with the package. Its adapter reports every status of the Google API as a failure, except an
empty result. Other services are added as [custom adapters](custom-adapters.md).

## Dependency Injection

`Taldres\GeocodeCache\GeocodeCacheManager` is bound as a singleton, so you can inject it instead of using the facade.
`$manager->driver()` returns a `CachedGeocoder`, which offers `geocode()` and `refresh()`.
