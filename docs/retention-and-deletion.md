# Retention and Deletion

## What Is Stored

One row per query, in the table `geocode_cache` (configurable):

| Column | Purpose |
| --- | --- |
| `query_hash` | Unique key of the complete query: a SHA-256 hash of the data source and either the normalised search text, country, language, region and bounds, or the unchanged place ID. |
| `source` | The data provider, for example `google`. |
| `latitude`, `longitude` | The coordinates, with the configured `precision` (7 decimals at most). |
| `place_id` | The optional Google place ID. |
| `fetched_at` | When the data was actually fetched from the service. |
| `expires_at` | The end of the period the entry may be used and kept. |
| `last_requested_at` | The time of the last request. Used by the [refresh](refreshing.md) only. |
| `query_payload` | The encrypted query. Only filled when the [refresh](refreshing.md) is enabled and `refresh.store_query` is on. |

Not stored: the formatted address, the address components, the viewport, the result type or anything else of the
response. The hash is not an anonymisation. A known address can be hashed again and compared.

The optional [statistics](statistics.md) live in a second table with the counters per hash and day. They hold no
coordinates, but they keep hashes for `statistics.retention_days`.

## The Period

- `expires_at = fetched_at + ttl_days` (or `source_ttl_days` for the source of the adapter), calculated in seconds, so a change of the clock does not shift it.
- An entry is valid while `expires_at` is in the future. From `expires_at` on it is expired.
- The period starts with the actual fetch. **Reading an entry does not extend it.** Only a new, successful lookup
  stores a new period for the new result.
- A change of `ttl_days` or `source_ttl_days` applies to the entries stored afterwards. Entries that are already stored
  keep their `expires_at`, unless the adapter reports a stricter maximum than their period: then they are deleted instead
  of returned. To apply a shorter retention to everything at once, delete the entries (see below).
- The check happens on every access. An expired entry is deleted when it is found, before the service is asked.
- With the default of 29 days, an entry from Google is gone before the maximum of 30 consecutive calendar days is over,
  if the prune command runs at least daily.

## Deleting What Nobody Asks For

Not returning an expired entry is not enough, because the data has to be deleted. The command does that:

```bash
php artisan geocode-cache:prune            # deletes all expired entries, in chunks
php artisan geocode-cache:prune --pretend  # only counts them
```

Schedule it as described in [Installation](installation.md). `php artisan about --only=geocode_cache` tells you if it is
scheduled. The command uses `Illuminate\Database\Eloquent\MassPrunable`, so it works with `model:prune --model=` as well.

To delete everything at once, for example when you switch the retention policy or the contract:

```php
Taldres\GeocodeCache\Models\GeocodeCacheEntry::query()->delete();
```

## Time

The dates are stored as UTC in `dateTime` columns and handed out in the timezone of the application. Changing
`app.timezone` or a daylight saving change cannot shift the end of a period. If you query or write the table yourself with `Builder::update()`, `where()` or `DB::table()`, Laravel skips the casts: pass
`->utc()` Carbon values, otherwise a timestamp in a non-UTC timezone is stored or compared as local wall-clock time and
moves the end of a period by the offset.

A retention of 30 days is allowed for Google results, but an entry
stays in the table until the next prune, so the data can be kept longer than the 30 days by up to the prune interval. Use
the default of 29 days unless you know what you are doing.

## Copies the Package Cannot Delete

The package cleans its own table. Look at the other places where coordinates and queries can end up:

- **Logs and error reports.** Do not log results or queries. The package never puts an address or an API key into an
  exception message, and its own [log](logging.md) holds no queries or coordinates.
- **Queue payloads and failed jobs.** A job that carries coordinates keeps them for as long as the job table does. Pass
  the query and look it up in the job instead.
- **Other caches.** Do not copy a result into another cache, a session or your own models for longer than `expiresAt`.
- **Database exports and backups.** You decide how long backups live. When you restore a backup, run
  `php artisan geocode-cache:prune` before the application uses the data again, because restored rows may be expired.
- **HTTP client events and monitoring tools.** Laravel's HTTP client dispatches events that carry the request URL, with
  the API key and the searched address or place ID. A tool that records them, such as Telescope's client request watcher or
  Nightwatch, stores both. Exclude requests to the Geocoding API from it.
- **Database errors.** A failing query puts its bindings, such as coordinates and the key hash, into the exception
  message and thus into your logs. Keep such logs only as long as the coordinates may be kept.
- **The refresh.** With `refresh.store_query`, the table also holds the encrypted queries. They live and die with their
  rows. Without it the table holds no searched text.

## Limits

The package gives you a cache with retention and deletion rules. It cannot promise that your application complies with
Google's terms: that depends on how you use the data, on your contract and on later changes of the terms.
