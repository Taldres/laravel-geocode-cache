# Logging

The package logs nothing by default. To switch the log on, name a channel of `config/logging.php`:

```
GEOCODE_CACHE_LOG_CHANNEL=geocode
```

```php
// config/logging.php
'channels' => [
    'geocode' => ['driver' => 'daily', 'path' => storage_path('logs/geocode.log'), 'days' => 14],
],
```

## What Is Logged

| Level | Message | Context |
| --- | --- | --- |
| `debug` | The geocoding service was asked. | `source`, `found` |
| `warning` | The geocoding lookup failed. | `source`, `reason` |
| `warning` | A geocoding lookup gave up waiting for the lock of the same query. | `source` |
| `info` | Expired geocode cache entries were deleted. | `deleted`, `statistics` |
| `info` or `warning` | The geocode cache refresh finished. | `source`, `refreshed`, `copied`, `without_result`, `skipped`, `failed`, `by_place_id` (how many calls used a place ID) |
| `error` | The geocode cache refresh stopped after repeated failures. | `failures` |

`reason` is a fixed code of the `GeocodingFailedException`: `connection`, `http_503` (the HTTP status), `status_REQUEST_DENIED` (the status
of the service), `malformed_response`, `lock_timeout` or `unspecified`. It never holds text from the service or
from a library. The message of the exception is not logged.

The same facts are available as [events](events.md).

## What Is Never Logged

The searched text, the key hash, coordinates, place IDs and the API key. A log is a copy of data that the package cannot
delete. Google data in it would outlive the retention of the cache. Keep this in mind when you log the results of
`geocode()` yourself, and give the channel a retention of its own (the `days` option of the `daily` driver).

Logging happens while a query's lock is held, so use a fast channel such as a local file and not a webhook.

A log channel that does not exist or fails never breaks a lookup. `php artisan about --only=geocode_cache` shows the
channel.
