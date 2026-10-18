# Request Statistics

The cache table tells you what is stored, not how often it is used. The statistics count, per query hash and day, how
many requests were answered from the cache and how many had to ask the service. You see which entries are worth a
[refresh](refreshing.md) and what the service calls cost. They are off by default, because every request writes a
counter.

```
GEOCODE_CACHE_STATISTICS_ENABLED=true
```

The table comes with the second migration of the package (`php artisan vendor:publish --tag=geocode-cache-migrations`).

## What Is Counted

One row per hash and **UTC day**:

| Counter | Counts |
| --- | --- |
| `hits` | Requests answered from the cache, including a request that waited for the lock and found the entry another process stored. |
| `lookups` | Requests without a valid entry, for which the service was asked. This includes lookups that found nothing or failed, because the call costs money. |
| `refreshes` | Calls of the [refresh](refreshing.md). They are not requests. |

A row holds no query, no coordinates and no place ID. A failing write never breaks a lookup.

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

*Active days* is the number of days with at least one request. The hashes are shortened to twelve characters. You can
also query the table with `Taldres\GeocodeCache\Models\GeocodeCacheStatistic`, with `->utc()` dates.

## Retention

The prune command deletes rows older than `statistics.retention_days` (90 by default).

A hash can be confirmed against a known address, and the statistics keep hashes longer than the entries live. Consider
them personal data if the addresses you search for are, choose the retention accordingly and delete the table when you no
longer need the numbers:

```php
Taldres\GeocodeCache\Models\GeocodeCacheStatistic::query()->delete();
```
