# Request Statistics

The cache table tells you what is stored, not how often it is used. The statistics count, per query hash and day, how
many requests were answered from the cache and how many had to ask the service. You see how regularly a hash is
requested, which entries are worth a [refresh](refreshing.md) and what the service calls cost.

The statistics are off by default, because every request writes a counter.

```
GEOCODE_CACHE_STATISTICS_ENABLED=true
```

The table comes with the second migration of the package (`php artisan vendor:publish --tag=geocode-cache-migrations`).

## What Is Counted

One row per hash and **UTC day**, with three counters:

| Counter | Counts |
| --- | --- |
| `hits` | Requests answered from the cache. |
| `lookups` | Requests without a valid entry, for which the service was asked. This includes lookups that found nothing or failed, because the call costs money. |
| `refreshes` | Calls of the [refresh](refreshing.md). They are not requests. |

A request that waited for the lock and then found the entry another process stored counts as a hit.

A row holds no query, no coordinates and no place ID, only the hash, the day and the counters. Writing a counter never
touches the cache entry, and a failing write never breaks a lookup.

## Showing Them

```bash
php artisan geocode-cache:statistics                  # the last 30 days, the 20 most requested hashes
php artisan geocode-cache:statistics --days=7 --limit=50
```

```
+--------------+-------------+------+---------+-----------+------------+------------+
| Hash         | Active days | Hits | Lookups | Refreshes | First day  | Last day   |
+--------------+-------------+------+---------+-----------+------------+------------+
| 5f1c0a9e2b7d | 27 of 30    | 412  | 1       | 1         | 2026-09-18 | 2026-10-17 |
| a03b77c1d9e4 | 2 of 30     | 3    | 2       | 0         | 2026-09-30 | 2026-10-12 |
+--------------+-------------+------+---------+-----------+------------+------------+
```

*Active days* is the number of days with at least one request. A hash with 27 of 30 is used every day, one with 2 of 30
only now and then. The hashes are shortened to twelve characters. The table is also an ordinary table, so you can
query it yourself, with `Taldres\GeocodeCache\Models\GeocodeCacheStatistic`. Pass `->utc()` dates when you do (see
[Retention and Deletion](retention-and-deletion.md)).

## Retention

The prune command deletes rows older than `statistics.retention_days` (90 by default) together with the expired entries,
so schedule it as described in [Installation](installation.md).

A hash can be confirmed against a known address. The statistics keep hashes for the retention above, which is longer
than the entries live. They hold no Google data, but consider them personal data if the addresses you search for are.
Choose the retention accordingly, and delete the table when you no longer need the numbers:

```php
Taldres\GeocodeCache\Models\GeocodeCacheStatistic::query()->delete();
```
