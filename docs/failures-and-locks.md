# Failures, Empty Results and Locks

## Failure and Empty Result Are Different

| Situation | Behaviour |
| --- | --- |
| The service found coordinates | They are stored and returned. |
| The service found nothing (`ZERO_RESULTS`) | `geocode()` returns `null`. Nothing is stored. |
| The service could not be reached, answered with an error or sent an unusable response | A `GeocodingFailedException` is thrown. Nothing is stored or changed. |

An empty result is not cached, so the same query asks the service again. If your application often looks up queries
that come back empty, remember them yourself for a short time.

A failed lookup never extends the period of an existing entry and never makes an expired entry usable again. Only a
new, successful lookup stores a new period.

## Exceptions

All of them live in `Taldres\GeocodeCache\Exceptions` and implement `GeocodeCacheException`.

| Exception | Extends | Thrown when |
| --- | --- | --- |
| `GeocodingFailedException` | `RuntimeException` | The service could not be reached, answered with an error or sent an unusable response. |
| `GeocodingLockTimeoutException` | `GeocodingFailedException` | Another process still looks up the same query after `lock.wait` seconds. The service was not asked, so trying again later is safe. |
| `InvalidConfigurationException` | `InvalidArgumentException` | A setting, a driver or an adapter is unusable. It is thrown before the service is called. |
| `UnsupportedQueryException` | `LogicException` | The query is a place ID and the adapter cannot look it up. It is thrown before the cache is read. |

Invalid arguments of your own call, such as an empty search text, stay a plain `InvalidArgumentException`. An exception
that your own adapter or a library throws is passed on as it is.

```php
try {
    $result = GeocodeCache::geocode($query);
} catch (GeocodingLockTimeoutException) {
    // Try again in a moment, for example by releasing the queued job back to the queue.
} catch (GeocodingFailedException $exception) {
    report($exception);
}
```

The messages of the exceptions never contain the request URL, because it holds the API key and the searched address.

### Reason Codes

`$exception->reason` holds a fixed code: `connection`, `http_<status>` (such as `http_503`), `status_<CODE>` (such as
`status_REQUEST_DENIED`), `status_unknown`, `malformed_response`, `lock_timeout` or `unspecified`. The same code reaches
the [log and the events](events-and-logging.md). New codes may be added, so match the ones you know and treat any
other value as a failure of the service. Never parse the message text.

## Parallel Requests

A lock per query stops parallel requests from calling the service for the same query:

1. A request without a valid entry takes the lock `geocode-cache:{hash}`.
2. It looks at the cache again, because the process that held the lock may have stored the entry meanwhile.
3. Only if there is still none, it calls the service, stores the result and releases the lock.

A request that cannot get the lock waits up to `lock.wait` seconds. If the lock is still taken, it uses the entry if one
appeared and otherwise throws a `GeocodingLockTimeoutException`. Locks of different queries do not block each other.

The lock needs a cache store with atomic locks. Set `lock.store` if your default store has none, for example the `array`
store. `lock.seconds` must exceed the timeout of the driver and be 1 or more, `lock.wait` must be 0 or more.

Keep `geocode()` outside of database transactions when the locks live in the `database` cache store on the same
connection. Otherwise the lock row belongs to your transaction, other processes can wait for it beyond `lock.wait`, and
a rollback undoes the write. Alternatively give the locks a store of their own with `lock.store`.
