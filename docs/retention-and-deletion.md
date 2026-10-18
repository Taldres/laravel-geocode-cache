# Retention and Deletion

## What Is Stored

One row per query in the table `geocode_cache` (configurable):

| Column | Purpose |
| --- | --- |
| `query_hash` | Unique key of the query: a SHA-256 hash of the source and either the normalised search text, country, language, region and bounds, or the unchanged place ID. |
| `source` | The data provider, for example `google`. |
| `latitude`, `longitude` | The coordinates, with the configured `precision` (7 decimals at most). |
| `place_id` | The optional Google place ID. |
| `fetched_at` | When the data was fetched from the service. |
| `expires_at` | The end of the period the entry may be used and kept. |
| `last_requested_at` | The time of the last request. Used by the [refresh](refreshing.md) only. |
| `query_payload` | The encrypted query. Only filled with `refresh.store_query`. |

Not stored: the formatted address, the address components, the viewport or anything else of the response. The hash is
not an anonymisation, because a known address can be hashed again and compared.

The optional [statistics](statistics.md) live in a second table. They hold no coordinates, but they keep hashes for
`statistics.retention_days`.

## The Period

- `expires_at = fetched_at + ttl_days` (or `source_ttl_days` for the source of the adapter), calculated in seconds.
- An entry is valid while `expires_at` is in the future.
- The period starts with the fetch. **Reading an entry does not extend it.** Only a new, successful lookup stores a new
  period.
- The check happens on every access. An expired entry is deleted when it is found, before the service is asked.
- A changed `ttl_days` applies to entries stored afterwards. An entry whose period exceeds the maximum of its adapter is
  deleted instead of returned. To apply a shorter retention to everything at once, delete the entries (see below).
- With the default of 29 days, an entry from Google is gone before the 30 days are over, if the prune command runs at
  least daily. A retention of 30 days is allowed, but an entry stays in the table until the next prune.

## Deleting What Nobody Asks For

Not returning an expired entry is not enough, because the data has to be deleted:

```bash
php artisan geocode-cache:prune            # deletes all expired entries, in chunks
php artisan geocode-cache:prune --pretend  # only counts them
```

Schedule it as described in [Installation](installation.md). The command uses
`Illuminate\Database\Eloquent\MassPrunable`, so `model:prune --model=` works as well. To delete everything at once, for
example when you change the retention policy or the contract:

```php
Taldres\GeocodeCache\Models\GeocodeCacheEntry::query()->delete();
```

## Time

Dates are stored as UTC and handed out in the timezone of the application, so a change of `app.timezone` or a daylight
saving change cannot shift the end of a period. If you query or write the table yourself with `Builder::update()`,
`where()` or `DB::table()`, Laravel skips the casts: pass `->utc()` Carbon values. With a [connection of its own](configuration.md#a-connection-of-its-own), name it: `DB::connection(config('geocode-cache.connection'))->table(...)`.

## Copies the Package Cannot Delete

The package cleans its own table. Look at the other places where coordinates and queries can end up:

- **Logs and error reports.** Do not log results or queries. The package never puts an address or an API key into an
  exception message, and its own [log](events-and-logging.md#log) holds no queries or coordinates.
- **Queue payloads and failed jobs.** Pass the query and look it up in the job instead of carrying coordinates.
- **Other caches and your own models.** Do not copy a result there for longer than `expiresAt`.
- **Backups.** When you restore one, run `php artisan geocode-cache:prune` before the application uses the data again,
  because restored rows may be expired.
- **HTTP client monitoring.** Laravel's HTTP client events carry the request URL, with the API key and the searched
  address. Exclude requests to the Geocoding API from tools such as Telescope or Nightwatch.
- **Database errors.** A failing query puts its bindings, such as coordinates and the hash, into the exception message
  and thus into your logs.
- **The refresh.** With `refresh.store_query` the table also holds the encrypted queries. They live and die with their rows.

The package cannot promise that your application complies with Google's terms, see [Google Terms](google-terms.md).
