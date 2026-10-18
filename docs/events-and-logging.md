# Events and Logging

Two events and an optional log tell you about calls of the service. They carry the same data. Neither ever holds the
searched text, the key hash, coordinates, place IDs or the API key.

## Events

| Event | Properties | Dispatched |
| --- | --- | --- |
| `Taldres\GeocodeCache\Events\GeocodeServiceCalled` | `source`, `found` | After the service answered, with or without a result. |
| `Taldres\GeocodeCache\Events\GeocodeLookupFailed` | `source`, `reason` | When the service could not be reached, reported an error or sent an unusable response. |

`source` is the source of the adapter, such as `google`. `found` is `false` for an empty result. `reason` is one of the
[reason codes](failures-and-locks.md#reason-codes), or `unexpected` if the adapter threw something else.

```php
use Illuminate\Support\Facades\Event;
use Taldres\GeocodeCache\Events\GeocodeLookupFailed;
use Taldres\GeocodeCache\Events\GeocodeServiceCalled;

Event::listen(GeocodeServiceCalled::class, fn (GeocodeServiceCalled $event) => Metrics::increment("geocode.{$event->source}"));
Event::listen(GeocodeLookupFailed::class, fn (GeocodeLookupFailed $event) => Metrics::increment("geocode.failed.{$event->reason}"));
```

Only a call of the service dispatches an event: a lookup that is not in the cache and every service call of
`geocode-cache:refresh`. An answer from the cache dispatches nothing, [Request Statistics](statistics.md) count those.
A lock timeout dispatches nothing either, because the service was not asked.

Events are dispatched after the lock of the query was released, but before `geocode()` returns. Keep listeners fast or
make them queued (`ShouldQueue`), and do not look up the same query from a listener. A listener that throws does not
undo the lookup: the package writes a warning to the log and passes the exception to `report()`. This also swallows
failed assertions inside a listener, so assert on `Event::fake()` in your tests instead:

```php
use Illuminate\Support\Facades\Event;

Event::fake([GeocodeServiceCalled::class]);

GeocodeCache::geocode(GeocodeQuery::make('Alexanderplatz 1', 'DE'));

Event::assertDispatched(GeocodeServiceCalled::class, fn ($event) => $event->found);
```

An event is dispatched at most once per call, so it is lost if the process dies in between, and events of parallel
processes may arrive in a different order than their calls happened.

## Log

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

| Level | Message | Context |
| --- | --- | --- |
| `debug` | The geocoding service was asked. | `source`, `found` |
| `warning` | The geocoding lookup failed. | `source`, `reason` |
| `warning` | A geocoding lookup gave up waiting for the lock of the same query. | `source` |
| `info` | Expired geocode cache entries were deleted. | `deleted`, `statistics` |
| `info` or `warning` | The geocode cache refresh finished. | `source`, `refreshed`, `copied`, `without_result`, `skipped`, `failed`, `by_place_id` |
| `error` | The geocode cache refresh stopped after repeated failures. | `failures` |

A log is a copy of data that the package cannot delete, so give the channel a retention of its own (the `days` option
of the `daily` driver) and do not log results or queries yourself. Logging happens while the lock of a query is held, so
use a fast channel such as a local file. A log channel that does not exist or fails never breaks a lookup.
`php artisan about --only=geocode_cache` shows the channel.
