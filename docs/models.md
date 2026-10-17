# Coordinates for Your Models

A venue, a company or a property has a place that rarely changes, and you want its coordinates when you need them. The
coordinates themselves may only live for a limited time in your database, so the model keeps what lasts and asks the
cache for the rest:

- **What lasts:** the address your own users entered, and the Google place ID. Google lets you keep place IDs
  indefinitely. Check the terms that apply to you, see [Google Terms](google-terms.md).
- **What does not:** latitude and longitude. They stay in the cache table with their expiry and are deleted with it.

Do not add `latitude` and `longitude` columns to your model, and do not add a relation or a foreign key to the cache
table. An entry is deleted when it expires and gets a new row when it is fetched again, so a reference would point to
nothing after a few weeks, and reading the row directly would bypass the expiry check.

## A Method on the Model

```php
use Taldres\GeocodeCache\Data\GeocodeQuery;
use Taldres\GeocodeCache\Data\GeocodeResult;
use Taldres\GeocodeCache\Data\PlaceIdQuery;
use Taldres\GeocodeCache\Facades\GeocodeCache;

class Venue extends Model
{
    /**
     * Calls the cache, and the service if the cache has no valid entry. Name it so the call is visible.
     */
    public function coordinates(): ?GeocodeResult
    {
        if ($this->google_place_id !== null) {
            $result = GeocodeCache::geocode(PlaceIdQuery::make($this->google_place_id));
        }

        // The place is unknown (anymore), or there never was a place ID: search for the address.
        $result ??= GeocodeCache::geocode(GeocodeQuery::make($this->full_address, country: $this->country));

        $this->rememberPlaceId($result);

        return $result;
    }

    private function rememberPlaceId(?GeocodeResult $result): void
    {
        if ($result?->placeId !== null && $result->placeId !== $this->google_place_id) {
            $this->forceFill(['google_place_id' => $result->placeId])->saveQuietly();
        }
    }
}
```

The first call finds the place through the address and remembers its ID. Later calls use the ID, which does not change
when someone words the address differently. The package never writes to your models: saving a new place ID is your
decision, as above.

This needs `store_place_id` to be `true` (the default), otherwise `$result->placeId` is `null`, and an adapter that can
look a place ID up, which the `google` adapter does. Both calls throw a `GeocodingFailedException` when the service fails,
so decide where to catch it.

## A Separate Location Model

If several models share places, give the place a model of its own and relate to it as usual:

```php
class Location extends Model
{
    // The columns: id, google_place_id (nullable), your address columns. No coordinates.
    public function coordinates(): ?GeocodeResult { /* as above */ }
}

class Venue extends Model
{
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }
}

$venue->location;                // a normal relation, no service call
$venue->location->coordinates(); // the cache, and the service if needed
```

When the entry expires, the `Location` and the relation stay. The next `coordinates()` call fetches the coordinates again.

## Things to Keep in Mind

- **No hidden service calls.** Do not call `coordinates()` from an accessor you serialise, from `$appends` or in a
  loop over many models. Each call reads the cache table and may ask the service.
- **Do not keep the result.** `latitude` and `longitude` are valid until `expiresAt`. Do not copy them into your own
  columns, caches or queue payloads.
- **Place IDs can go stale.** Google can retire a place ID. The lookup then finds nothing and returns `null`, so the
  address is used and the new ID is remembered, as in the example.
- **The hash is not a key.** The `query_hash` of the cache table is derived from the query, can change between
  versions of the package and is not meant to be stored in your tables.
