# Configuration

Publish the configuration with `php artisan vendor:publish --tag=geocode-cache-config`. The file is
`config/geocode-cache.php`.

| Key | Environment variable | Default | Meaning |
| --- | --- | --- | --- |
| `driver` | `GEOCODE_CACHE_DRIVER` | `google` | The driver used for lookups: `google` or a [custom one](custom-adapters.md). |
| `table` | `GEOCODE_CACHE_TABLE` | `geocode_cache` | The name of the table. Set it before you migrate. |
| `ttl_days` | `GEOCODE_CACHE_TTL_DAYS` | `29` | The days a result may be used and kept, counted from the fetch. 1 to 30 for results from Google. |
| `source_ttl_days` | none | `[]` | The retention of a source that differs from `ttl_days`, for example `['mapbox' => 365]`. The adapter can set a maximum. |
| `precision` | `GEOCODE_CACHE_PRECISION` | empty (maximum) | The decimals of latitude and longitude that are stored and returned, 0 up to the maximum of the adapter (7 for Google). |
| `store_place_id` | `GEOCODE_CACHE_STORE_PLACE_ID` | `true` | Whether the place ID of a result is stored next to the coordinates. |
| `drivers.google.api_key` | `GOOGLE_MAPS_GEOCODING_API_KEY` | none | The API key. |
| `drivers.google.endpoint` | none | Google's endpoint | The geocoding endpoint. |
| `drivers.google.timeout` | none | `5` | Seconds to wait for the service. |
| `lock.store` | `GEOCODE_CACHE_LOCK_STORE` | default cache store | The cache store that holds the locks. |
| `lock.seconds` | none | `30` | Seconds until a lock frees itself. It must exceed the driver timeout. |
| `lock.wait` | none | `10` | Seconds a request waits for a parallel lookup of the same query. |
| `statistics.enabled` | `GEOCODE_CACHE_STATISTICS_ENABLED` | `false` | Counts requests per hash and day, see [Request Statistics](statistics.md). |
| `statistics.table` | `GEOCODE_CACHE_STATISTICS_TABLE` | `geocode_cache_statistics` | The name of the statistics table. Set it before you migrate. |
| `statistics.retention_days` | `GEOCODE_CACHE_STATISTICS_RETENTION_DAYS` | `90` | Days the prune command keeps the counters. |
| `logging.channel` | `GEOCODE_CACHE_LOG_CHANNEL` | empty (off) | A log channel for the [operational log](events-and-logging.md#log). |
| `refresh.enabled` | `GEOCODE_CACHE_REFRESH_ENABLED` | `false` | Stores what the [refresh](refreshing.md) needs. |
| `refresh.store_query` | `GEOCODE_CACHE_REFRESH_STORE_QUERY` | `false` | Stores the query too, encrypted, so a refresh asks for the address again. |
| `refresh.expiring_within_days` | none | `3` | Entries that expire within this many days are refreshed. |
| `refresh.requested_within_days` | none | `14` | Entries not requested during this many days are not refreshed. |
| `refresh.touch_interval` | none | `3600` | Seconds between two writes of the last request time of an entry. |
| `refresh.limit` | none | `100` | The maximum number of service calls of one refresh run. |

An unusable setting is rejected with an `InvalidConfigurationException` before the service is called: a `ttl_days`
that is zero, negative, not an integer or above the maximum of the adapter, a `precision` above the maximum, a missing
API key, or a driver timeout that is not shorter than `lock.seconds`.

`precision` decides how exact the stored position is: 4 decimals are about 11 metres, 2 are about 1.1 kilometres. A
coarser position is also less sensitive data. The precision is not part of the cache key, so existing entries keep the
precision they were stored with. A changed `ttl_days` applies to entries stored afterwards.

The retention belongs to the source and not to the driver, because drivers with the same source share their entries.
Switching to a driver with another source invalidates nothing: the entries of the old source stay unused until they
expire.
