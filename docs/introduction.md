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
2. The package returns a valid entry from the database.
3. If there is none, or it has expired, an adapter asks the geocoding service. A lock per query makes parallel requests
   wait for one lookup instead of starting their own.
4. The package stores the coordinates and the optional place ID with the time of the fetch and the time of expiry.
5. A scheduled command deletes expired entries, also those nobody asks for again.

An expired entry is never returned, not even when the service is down. A failed lookup throws an exception. It neither
extends the period of an old entry nor makes expired coordinates usable again.

## Terms

| Term | Meaning |
| --- | --- |
| **Adapter** | The class that asks one geocoding service and implements `Contracts\Adapter`. It knows what its service can do: the maximum precision, the maximum retention and whether it can look a place ID up. |
| **Driver** | The name under which the manager provides an adapter with the cache around it: `drivers.google` in the configuration, `GeocodeCache::driver('google')`, `--driver=`. Credentials and timeouts belong to the driver. |
| **Source** | The data provider whose terms apply, for example `google`. It is stored with every entry and is part of the cache key. Drivers with the same source share their entries, and the retention is configured per source. |

The `google` adapter ships with the package and calls the Google Geocoding API with Laravel's HTTP client. Other services
can be added as [custom adapters](custom-adapters.md).

## Limits

- It does not guarantee that your use of Google data complies with Google's terms. Whoever integrates the package must
  read and follow the terms of Google and of every other provider they use. Retention, attribution, map display rules and
  your contract are your responsibility, see [Google Terms](google-terms.md).
- It stores only coordinates and the optional place ID, no formatted addresses or other fields of a response.
- It cannot clean copies outside its own table, such as logs, queue payloads, other caches and backups, see
  [Retention and Deletion](retention-and-deletion.md).
- It does not cache empty results.
