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

All of it, plus the data source, is part of the cache key. The search text is trimmed, squished and lower-cased, and the
country, language and region are normalised, so `'Berlin '` and `'berlin'` share an entry while `'Springfield'` in the
US and in Canada do not. The facade also accepts a plain string for a query without parameters.

Pass a postal code as a string, so leading zeros survive, and give a country. A postal code or a place name returns a
representative point of the area, not the point of a street address:

```php
GeocodeCache::geocode(GeocodeQuery::make('01067', country: 'DE'));
```

## The Result

`geocode()` returns a `GeocodeResult`, or `null` if the service found nothing:

| Property | Meaning |
| --- | --- |
| `latitude`, `longitude` | The coordinates, rounded to `precision` decimals (7 at most). |
| `placeId` | The Google place ID, or `null` if it is not available or `store_place_id` is off. |
| `source` | The data provider, for example `google`. |
| `fetchedAt` | When the data was fetched from the service. |
| `expiresAt` | The moment from which the entry is expired and may not be used. |

A result from the cache and a fresh one look the same. Do not keep the coordinates beyond `expiresAt`, for example in
your own models, see [Coordinates for Your Models](models.md).

## Looking a Place Up by Its ID

The ID of a result can be looked up again later, without the address:

```php
use Taldres\GeocodeCache\Data\PlaceIdQuery;

$result = GeocodeCache::geocode(PlaceIdQuery::make('ChIJ...'));
```

It goes through the same cache, with the same expiry and locks. The cache key is the source and the ID exactly as given,
because a place ID is opaque. Language, region and bounds do not apply. The result is `null` if the service does not know
the place (anymore), so you can search for the address instead.

The `google` adapter can look a place ID up, and a [custom adapter](custom-adapters.md#place-ids) can if it implements
the optional interface. For any other adapter the call throws an `UnsupportedQueryException` before the cache is read.
`GeocodeCache::driver()->supports($query)` tells beforehand.

## Drivers

```php
GeocodeCache::geocode($query);                    // the default driver
GeocodeCache::driver('google')->geocode($query);  // a specific driver
```

`GeocodeCacheManager` is bound as a singleton, so you can inject it instead of using the facade. `driver()` returns a
`CachedGeocoder` with `geocode()` and `refresh()`.
