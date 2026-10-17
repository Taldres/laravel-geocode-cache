# Failures, Empty Results and Locks

## Failure and Empty Result Are Different

| Situation | Behaviour |
| --- | --- |
| The service found coordinates | They are stored and returned. |
| The service found nothing (`ZERO_RESULTS`) | `geocode()` returns `null`. Nothing is stored. |
| The service could not be reached, answered with an error or sent an unusable response | `GeocodingFailedException` is thrown. Nothing is stored or changed. |

An empty result is not cached, so asking for the same query again asks the service again. If your application looks
up queries that often come back empty, remember them yourself for a short time.

A failed lookup never extends the period of an existing entry and never makes an expired entry usable again. An
expired entry is deleted when it is accessed, before the service is called. Only a new, successful lookup stores a new
period.

The messages of `GeocodingFailedException` do not contain the request URL, because it holds the API key and the
searched address or place ID.

## Exceptions

All of them live in `Taldres\GeocodeCache\Exceptions` and implement `GeocodeCacheException`, so one `catch` handles
these four.

| Exception | Extends | Thrown when |
| --- | --- | --- |
| `GeocodingFailedException` | `RuntimeException` | The service could not be reached, answered with an error or sent an unusable response. `$exception->reason` holds a fixed code such as `http_503`. |
| `GeocodingLockTimeoutException` | `GeocodingFailedException` | Another process still looks up the same query after `lock.wait` seconds. The service was not asked, so trying again later is safe. |
| `InvalidConfigurationException` | `InvalidArgumentException` | A setting, a driver or an adapter is unusable, such as an invalid `ttl_days`, a setting of the wrong type, an unknown driver name, a lock store that is not defined or has no atomic locks or a missing API key. It is thrown before the service is called. |
| `UnsupportedQueryException` | `LogicException` | The query is a place ID and the adapter cannot look it up. It is thrown before the cache is read. |

One kind of error is not covered by `GeocodeCacheException` and stays a plain `InvalidArgumentException`: invalid
arguments of your own call, such as an empty search text or coordinates out of range. An exception that your own
adapter or a library throws is passed on as it is, see [Custom Adapters](custom-adapters.md).

```php
try {
    $result = GeocodeCache::geocode($query);
} catch (GeocodingLockTimeoutException) {
    // Try again in a moment, for example by releasing the queued job back to the queue.
} catch (GeocodingFailedException $exception) {
    report($exception);
}
```

## Parallel Requests

A lock per query stops parallel requests from calling the service for the same query:

1. A request without a valid entry takes the lock `geocode-cache:{hash}`.
2. It looks at the cache again, because the process that held the lock may have stored the entry in the meantime.
3. Only if there is still none, it calls the service, stores the result and releases the lock.

A request that cannot get the lock waits up to `lock.wait` seconds. If the lock is still taken, it uses the entry if one
appeared and otherwise throws a `GeocodingLockTimeoutException`, a `GeocodingFailedException` that you can catch to try
again. Locks of different queries do not block each other.

The lock needs a cache store with atomic locks. Set `lock.store` (or `GEOCODE_CACHE_LOCK_STORE`) if your default store
has none, for example the `array` store in production or a store without lock support. `lock.seconds` must exceed the
timeout of the driver, otherwise the driver is rejected with an `InvalidConfigurationException`.

`lock.seconds` must be 1 or more and `lock.wait` 0 or more, otherwise a lookup fails before the service is called.

If you call `geocode()` inside a database transaction while the locks live in the `database` cache store on the same
connection, the lock row belongs to your transaction, other processes can wait for it beyond `lock.wait`, and a rollback
undoes the write. Keep the call outside of transactions, or give the locks a store of their own with `lock.store`.

Writing the entry is an upsert on the unique `query_hash` column, so a lock that expired early cannot cause a unique
constraint violation.
