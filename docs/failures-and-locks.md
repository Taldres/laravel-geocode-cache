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

## Parallel Requests

A lock per query stops parallel requests from calling the service for the same query:

1. A request without a valid entry takes the lock `geocode-cache:{hash}`.
2. It looks at the cache again, because the process that held the lock may have stored the entry in the meantime.
3. Only if there is still none, it calls the service, stores the result and releases the lock.

A request that cannot get the lock waits up to `lock.wait` seconds. If the lock is still taken, it uses the entry if one
appeared and otherwise throws a `GeocodingFailedException`. Locks of different queries do not block each other.

The lock needs a cache store with atomic locks. Set `lock.store` (or `GEOCODE_CACHE_LOCK_STORE`) if your default store
has none, for example the `array` store in production or a store without lock support. `lock.seconds` must exceed the
timeout of the driver, otherwise the driver is rejected with an `InvalidArgumentException`.

`lock.seconds` must be 1 or more and `lock.wait` 0 or more, otherwise a lookup fails before the service is called.

If you call `geocode()` inside a database transaction while the locks live in the `database` cache store on the same
connection, the lock row belongs to your transaction, other processes can wait for it beyond `lock.wait`, and a rollback
undoes the write. Keep the call outside of transactions, or give the locks a store of their own with `lock.store`.

Writing the entry is an upsert on the unique `query_hash` column, so a lock that expired early cannot cause a unique
constraint violation.
