# Events

The package dispatches two events, so your application can count calls of the service and see failures without parsing
the [log](logging.md). They carry the same data as the log lines of the same name and nothing else.

| Event | Properties | Dispatched |
| --- | --- | --- |
| `Taldres\GeocodeCache\Events\GeocodeServiceCalled` | `source`, `found` | After the service answered, with or without a result. |
| `Taldres\GeocodeCache\Events\GeocodeLookupFailed` | `source`, `reason` | When the service could not be reached, reported an error or sent an unusable response. |

`source` is the source of the adapter, such as `google`. `found` is `false` for an empty result. `reason` is the fixed
code of the `GeocodingFailedException` (`connection`, `http_503`, `status_REQUEST_DENIED`, `malformed_response` or
`unspecified`), never text from the service.

```php
use Illuminate\Support\Facades\Event;
use Taldres\GeocodeCache\Events\GeocodeLookupFailed;
use Taldres\GeocodeCache\Events\GeocodeServiceCalled;

Event::listen(GeocodeServiceCalled::class, fn (GeocodeServiceCalled $event) => Metrics::increment("geocode.{$event->source}"));
Event::listen(GeocodeLookupFailed::class, fn (GeocodeLookupFailed $event) => Metrics::increment("geocode.failed.{$event->reason}"));
```

## What Is Dispatched, and What Is Not

- Only a call of the service dispatches an event: a lookup that is not in the cache, and every call of
  `geocode-cache:refresh` (every service call it makes), so you also see failed refreshes. An answer from the cache dispatches nothing, because the
  cache hit is the hot path. [Request Statistics](statistics.md) count the hits.
- A lock timeout dispatches nothing, because the service was not asked. It still throws a `GeocodingLockTimeoutException`.
- An invalid setting or an unsupported query throws before the service is called and dispatches nothing.

## Guarantees

An event is dispatched at most once per call of the service. If the process dies between the answer of the service and
the end of the call, the event is lost. Only a `GeocodingFailedException` produces `GeocodeLookupFailed`: a custom
adapter has to throw it, see [Custom Adapters](custom-adapters.md). Two processes may dispatch their events in a
different order than their service calls happened.

## Never Carried

The searched text, the key hash, coordinates, place IDs and the API key. A listener that logs or stores them itself
should keep in mind that these copies outlive the retention of the cache.

## Listeners

The events are dispatched after the lock of the query was released, still inside the call of `geocode()` or `refresh()`.
A slow listener therefore does not hold up other processes that wait for the same query. It does delay the call that
triggered it, so do the work in a queued listener (`ShouldQueue`) and keep the synchronous part fast. The exception is a
`database` lock store on the same connection inside your transaction, where the release is only committed with it, see
[Parallel Requests](failures-and-locks.md#parallel-requests).

Do not look up the query the event is about from inside the listener. A listener cannot even know it, but one that
captured it would ask the service again after an empty or failed answer, and again from that call's listener.

A listener that throws does not undo the lookup or replace the exception of a failed one. The service may have been
paid for, so the package carries on, writes a warning with the class names of the event and the exception to the
[log](logging.md) and passes the exception to `report()`, so your exception handler sees it. This also swallows failed
assertions inside a listener, so assert on `Event::fake()` in your tests instead.

Inside a database transaction of your application the dispatch runs in a savepoint, so a failing statement of a listener
on the default connection does not abort the transaction on PostgreSQL. What the listener wrote before it failed is
rolled back.

## Testing

```php
Event::fake([GeocodeServiceCalled::class]);

GeocodeCache::geocode(GeocodeQuery::make('Alexanderplatz 1', 'DE'));

Event::assertDispatched(GeocodeServiceCalled::class, fn ($event) => $event->found);
```
