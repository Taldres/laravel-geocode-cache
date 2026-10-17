# Configuration

Publish the configuration with `php artisan vendor:publish --tag=geocode-cache-config`. The file is
`config/geocode-cache.php`.

| Key | Environment variable | Default | Meaning |
| --- | --- | --- | --- |
| `driver` | `GEOCODE_CACHE_DRIVER` | `google` | The driver used for lookups: `google` or a [custom one](custom-adapters.md). |
| `table` | `GEOCODE_CACHE_TABLE` | `geocode_cache` | The name of the table. Set it before you run the migration. |
| `ttl_days` | `GEOCODE_CACHE_TTL_DAYS` | `29` | The days a result may be used and kept, counted from the fetch. 1 to 30 for results from Google. |
| `source_ttl_days` | none | `[]` | The retention of a source that differs from `ttl_days`, for example `['mapbox' => 365]`. The adapter of the source can set a maximum. |
| `precision` | `GEOCODE_CACHE_PRECISION` | empty (maximum) | The decimals of latitude and longitude that are stored and returned. 0 up to the maximum of the adapter, which is 7 for Google. |
| `store_place_id` | `GEOCODE_CACHE_STORE_PLACE_ID` | `true` | Whether the place ID of a result is stored next to the coordinates. With `refresh.store_query` on, the place ID that a place ID query asks for is kept encrypted in any case. |
| `drivers.google.api_key` | `GOOGLE_MAPS_GEOCODING_API_KEY` | none | The API key. |
| `drivers.google.endpoint` | none | Google's endpoint | The geocoding endpoint. |
| `drivers.google.timeout` | none | `5` | Seconds to wait for the service. |
| `lock.store` | `GEOCODE_CACHE_LOCK_STORE` | default cache store | The cache store that holds the locks. |
| `lock.seconds` | none | `30` | Seconds until a lock frees itself. It must exceed the driver timeout, otherwise the driver is rejected with an `InvalidConfigurationException`. |
| `lock.wait` | none | `10` | Seconds a request waits for a parallel lookup of the same query. |
| `statistics.enabled` | `GEOCODE_CACHE_STATISTICS_ENABLED` | `false` | Counts requests per hash and day. See [Request Statistics](statistics.md). |
| `statistics.table` | `GEOCODE_CACHE_STATISTICS_TABLE` | `geocode_cache_statistics` | The name of the statistics table. Set it before you run the migration. |
| `statistics.retention_days` | `GEOCODE_CACHE_STATISTICS_RETENTION_DAYS` | `90` | Days the prune command keeps the counters. |
| `logging.channel` | `GEOCODE_CACHE_LOG_CHANNEL` | empty (off) | A log channel of `config/logging.php` for the [operational log](logging.md). |
| `refresh.enabled` | `GEOCODE_CACHE_REFRESH_ENABLED` | `false` | Stores what the [refresh](refreshing.md) needs. |
| `refresh.store_query` | `GEOCODE_CACHE_REFRESH_STORE_QUERY` | `false` | Stores the query too, encrypted, so a refresh asks for the address again. Without it only the place ID is stored and used. |
| `refresh.expiring_within_days` | none | `3` | Entries that expire within this many days are refreshed. |
| `refresh.requested_within_days` | none | `14` | Entries not requested during this many days are not refreshed. |
| `refresh.touch_interval` | none | `3600` | Seconds between two writes of the last request time of an entry. |
| `refresh.limit` | none | `100` | The maximum number of service calls of one refresh run. Entries of the same place share a call. |

An invalid `ttl_days` is rejected with an `InvalidConfigurationException` before the service is called: zero, negative,
not an integer, or above the maximum of the adapter (30 for results from Google). `source_ttl_days` holds the retention
of a source, because the terms of a provider decide it and drivers with the same source share their entries.

`precision` decides how exact the stored position is: 4 decimals are about 11 metres, 2 are about 1.1 kilometres. A coarser
position is also less sensitive data. The maximum depends on the adapter, because it reports what its service
delivers (`Adapter::maximumPrecision()`). The built-in `google` adapter delivers 7 decimals. A larger value
is rejected with an `InvalidConfigurationException` before the service is called, and the table never stores more than 7
decimals. The precision is not part of the cache key, so existing entries keep the precision they were stored with.

Adapters that read from the same provider share their cache entries, see [Custom Adapters](custom-adapters.md). Changing
the driver to one with another source does not invalidate anything, the entries of the old source just stay unused until they expire.
