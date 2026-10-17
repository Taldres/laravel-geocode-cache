# Introduction

Laravel Geocode Cache turns addresses, places and postal codes into coordinates and keeps the result in a database
table for a limited time. Its focus is the life cycle of the stored results: when an entry expires, that it is really
deleted, when it is fetched again and that parallel requests do not ask the service twice for the same query.

```php
use Taldres\GeocodeCache\Data\GeocodeQuery;
use Taldres\GeocodeCache\Facades\GeocodeCache;

$result = GeocodeCache::geocode(GeocodeQuery::make('Alexanderplatz 1, Berlin', country: 'DE'));

$result->latitude;   // 52.5219184
$result->expiresAt;  // the moment the entry may not be used anymore
```

## How It Works

1. Your application asks for the coordinates of a query.
2. The package looks for a valid entry in the database and returns it.
3. If there is none, or it has expired, an adapter asks the geocoding service. A lock per query makes sure that parallel
   requests wait for one lookup instead of starting their own.
4. The package stores the coordinates and the optional place ID with the time of the fetch and the time of expiry.
5. A scheduled command deletes expired entries, also those nobody asks for again.

An expired entry is never returned. This holds when the service is down as well: a failed lookup throws an exception,
and neither extends the period of an old entry nor makes expired coordinates usable again.

## Terms

| Term | Meaning |
| --- | --- |
| **Adapter** | The PHP class that asks one geocoding service and implements `Contracts\Adapter`. It knows what its service can do: the maximum precision, the maximum retention and whether it can look a place ID up. |
| **Driver** | The name under which the manager provides an adapter with the cache around it: `drivers.google` in the configuration, `GeocodeCache::driver('google')`, `GeocodeCache::extend('mapbox', ...)` and `--driver=`. The credentials and timeouts of an adapter belong to its driver. |
| **Source** | The data provider whose terms apply, for example `google`. It is stored with every entry and is part of the cache key. Drivers with the same source share their entries, and the retention is configured per source. |

The `google` adapter ships with the package. It calls the Google Geocoding API directly with Laravel's HTTP client. Other
services can be added with the [adapter contract](custom-adapters.md).

## What It Does Not Do

- It does not guarantee that your use of Google data complies with Google's terms. Retention is only one part of them.
  Attribution, the rules for maps and the contract that applies to you are your responsibility. See
  [Google Terms](google-terms.md).
- It stores no formatted addresses, address components or other fields of a response, only coordinates and the
  optional place ID.
- It does not clean up copies outside its own table, such as logs, queue payloads, other caches and backups. See
  [Retention and Deletion](retention-and-deletion.md).
- It does not cache empty results.
