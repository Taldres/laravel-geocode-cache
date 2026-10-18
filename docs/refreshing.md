# Refreshing Entries

By default an expired entry is simply gone, and the next request asks the service again. That is enough for most
applications. If you want queries that are still in use to be renewed shortly before they expire, so that no user
request has to wait for the service, enable the refresh.

The refresh is off by default. The cache key is only a hash, so the refresh needs something to ask the service with.
There are two ways:

- **By place ID (default).** The place ID stored next to the coordinates is used, and nothing else is stored. This needs
  `store_place_id` (the default) and an adapter that can look a place ID up, which the `google` adapter does.
- **By query.** With `GEOCODE_CACHE_REFRESH_STORE_QUERY=true` the query is stored too, **encrypted** with the
  application key, and the refresh asks for the address again. Use it for an adapter that cannot look place IDs up, or
  if you want Google to resolve the address anew each time.

```
GEOCODE_CACHE_REFRESH_ENABLED=true
```

```php
use Illuminate\Support\Facades\Schedule;

Schedule::command('geocode-cache:refresh')->daily()->onOneServer()->withoutOverlapping();
```

An entry that has neither a stored query nor a place ID is never refreshed. It expires as usual.

## What Is Stored

With the refresh enabled, each entry also holds `last_requested_at`, written at most once per `refresh.touch_interval`
seconds, and with `refresh.store_query` the encrypted `query_payload`. Searched addresses can be personal data, so treat
the table accordingly. Without `store_query` the table holds no searched text.

## What Is Refreshed

An entry is refreshed when all of this is true:

- it is still valid. Expired entries are deleted, not refreshed.
- it expires within `refresh.expiring_within_days`.
- it was requested during the last `refresh.requested_within_days`. A query nobody asks for anymore is not renewed.
  It expires and the prune command deletes it.
- the adapter can ask with what the entry offers: a stored query that can still be decrypted, or a place ID.

A refresh is a new lookup: a successful one stores the new coordinates with a new period, a failed one or one without a
result changes nothing. It is not a request, so it does not update `last_requested_at`, and an unused entry does not
keep itself alive. A refresh never brings back a row you deleted, for example to erase the data of a person.

## Query or Place ID

Both renew the same entry: the hash and the stored query stay, the coordinates and the period are new.

A refresh through the query asks Google to resolve the address anew, so a better match flows in. A refresh through the
place ID renews the place the address resolved to when the entry was stored. For a venue that does not move, that is the
same. For an ambiguous query, such as a postal code, it is not: store the query if you want Google to decide anew.

If Google no longer knows a place ID (`ZERO_RESULTS`), the entry is left alone and expires on time. The next request for
the address asks again and stores the new ID.

Switching `refresh.store_query` applies to entries stored afterwards. If you switch it off, remaining queries are not
used anymore and are removed when their entry is renewed or expires. To remove all of them at once:

```php
Taldres\GeocodeCache\Models\GeocodeCacheEntry::query()->toBase()->update(['query_payload' => null]);
```

If the application key changes, stored queries can no longer be decrypted. Entries with a place ID are then refreshed
through it, the others are skipped and expire.

## Several Queries, One Place

Different queries often resolve to the same place. They are separate entries, because the cache key is the query. The
refresh groups the due entries by `place_id`, asks the service once per place and copies the result to the other due
entries of that place. New queries still need their first call to learn which place they belong to.

## The Command

```bash
php artisan geocode-cache:refresh                 # uses the default driver
php artisan geocode-cache:refresh --driver=mapbox
php artisan geocode-cache:refresh --limit=20      # at most 20 service calls
php artisan geocode-cache:refresh --pretend       # only count what is due
```

A run makes at most `refresh.limit` service calls, oldest expiry first, and stops after five failures in a row, for
example when the API key was revoked. With the defaults it renews entries three days before they expire, so a daily
schedule is enough. Run it with the driver that stored the entries: an adapter that cannot look place IDs up skips
entries it can only ask by place ID, and they expire as usual.

If neither `refresh.store_query` nor `store_place_id` is on, or `store_query` is off and the adapter cannot look place
IDs up, there is nothing to refresh with. The command refuses to run, and `php artisan about` says so.
