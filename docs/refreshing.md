# Refreshing Entries

By default an expired entry is simply gone, and the next request asks the service again. That is enough for most
applications. If you want queries that are still in use to be renewed shortly before they expire, so that no user
request has to wait for the service, enable the refresh.

The refresh is off by default. The cache key is only a hash, so the refresh needs something to ask the service with.
There are two ways, and the first one needs no search text in your table:

- **By place ID (default).** The place ID of the entry is already stored next to the coordinates. The refresh asks the
  service for that place. Nothing else is stored. This needs `store_place_id` (the default) and an adapter that can look
  a place ID up, which the `google` adapter does.
- **By query.** With `GEOCODE_CACHE_REFRESH_STORE_QUERY=true` the query is stored too, **encrypted**, and the refresh
  asks for the address again. Use it for an adapter that cannot look place IDs up, or if you want Google to resolve the
  address anew each time (see below).

```
GEOCODE_CACHE_REFRESH_ENABLED=true
```

```php
use Illuminate\Support\Facades\Schedule;

Schedule::command('geocode-cache:refresh')->daily()->onOneServer()->withoutOverlapping();
```

## What Is Stored

With the refresh enabled, each entry also holds:

- `query_payload`, only with `refresh.store_query`: the normalised search, country, language, region and bounds of the
  query, or the place ID of a place ID query, **encrypted with the application key**. Searched addresses can be personal
  data. Treat the table accordingly. Without the setting the column stays empty and the table holds no searched text.
- `last_requested_at`: the time of the last request, written at most once per `refresh.touch_interval` seconds. It
  never changes `expires_at`.

An entry that has neither a stored query nor a place ID is never refreshed. It expires as usual.

## What Is Refreshed

An entry is refreshed when all of this is true:

- it is still valid. Expired entries are deleted, not refreshed.
- it expires within `refresh.expiring_within_days`.
- it was requested during the last `refresh.requested_within_days`. **A query nobody asks for anymore is not renewed.**
  It expires and the prune command deletes it.
- it belongs to the source of the adapter, and the adapter can ask with what the entry offers: a stored query that can
  still be decrypted, or a place ID.

A refresh is a new lookup: a successful one stores the new coordinates with a new period, a failed one changes nothing.
It is no request, so it does not update `last_requested_at`, and an unused entry does not keep itself alive.

If the service finds nothing anymore, the old entry stays until it expires.

## Query or Place ID

The refresh asks with the stored query if the entry has one, otherwise with its place ID. Both renew the same entry:
the hash and the stored query stay, the coordinates and the period are new.

Switching `refresh.store_query` applies to the entries stored afterwards. An entry that was stored without a query does
not get one when it is renewed. If you switch it off, a query that is still in the table is not used anymore and is
removed when its entry is renewed or expires. To remove all of them at once:

```php
Taldres\GeocodeCache\Models\GeocodeCacheEntry::query()->toBase()->update(['query_payload' => null]);
```

For a place ID that is not valid anymore, Google answers `ZERO_RESULTS`. The refresh counts that as "without result" and
leaves the entry alone, so it expires on time. The next request for the address asks the service again and stores the
new place ID. A stale ID costs one cache miss, not a failure. A malformed place ID is a bad request (HTTP 400) and
counts as a failure.

There is one difference. A refresh through the query asks Google to resolve the address anew, so a better match of the
address flows in. A refresh through the place ID renews the place that the address resolved to when the entry was stored.
For a venue that does not move that is the same. For an ambiguous query, such as a postal code, it is not: store the
query if you want Google to decide anew.

The entry is read again under the lock of the query. If it was deleted, has expired or was renewed since the run selected
it, nothing is fetched and nothing is written, so a refresh never brings back a row that you deleted, for example to
erase the data of a person. If the lock stays taken, the entry counts as a failure and is not touched.

## Several Queries, One Place

Different queries often resolve to the same place, for example "Alexanderplatz 1, Berlin" and "Alexanderplatz Berlin
10178". They are separate entries, because the cache key is the query, and the `place_id` column is indexed. The refresh
groups the due entries by `place_id`, asks the service **once per place** and copies the new coordinates, the new
`fetched_at` and the new `expires_at` to the other due entries of the same place. Entries without a place ID are refreshed
one by one.

Only entries that are due themselves get the copy: an entry of the same place that nobody requests anymore, or that does
not expire soon, is left alone and expires as usual. The copy is conditional on the state the entry was selected in, so an
entry that was deleted, renewed or replaced meanwhile is not touched. If the service reports another place ID than the
stored one, nothing is copied. The `refresh.limit` and `--limit` count service calls, not entries.

New queries still need their first call to learn which place they belong to. The grouping saves calls on refresh only.

## The Command

```bash
php artisan geocode-cache:refresh                 # uses the default driver
php artisan geocode-cache:refresh --driver=mapbox
php artisan geocode-cache:refresh --limit=20      # at most 20 service calls
php artisan geocode-cache:refresh --pretend       # only count the service calls and entries that are due
```

A run makes at most `refresh.limit` calls, oldest expiry first, to cap the cost, and it stops after five failures in a
row, for example when the API key was revoked. Run it before the entries run out of time: with the defaults it refreshes
entries three days before they expire, so a daily schedule is enough.

An adapter that cannot look place IDs up skips the entries it can only ask for by place ID, and a place ID query that
was stored with `refresh.store_query` needs that lookup too. Those entries expire as usual. A skipped entry still counts
against `refresh.limit` and `--limit`, because the run selects its entries before it knows what the adapter can answer,
so run the refresh with the driver that stored them.

With `refresh.store_query`, the place ID of a place ID query is kept encrypted in `query_payload`, also when
`store_place_id` is off, because the refresh needs it to ask again.

If neither `refresh.store_query` nor `store_place_id` is on, there is nothing to refresh with. The same holds if
`refresh.store_query` is off and the adapter cannot look place IDs up. The command refuses to run in both cases and
`php artisan about` says so.

A place ID that Google rejects as malformed is a failure, and five failures in a row stop the run. Such an entry stays
at the front of the run until it expires, which takes at most `refresh.expiring_within_days`. Place IDs come from Google,
so this only happens if Google changes what it accepts.

If the application key changes, stored queries can no longer be decrypted. Entries with a place ID are then refreshed
through it, the others are skipped and expire.
