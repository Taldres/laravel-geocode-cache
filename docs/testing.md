# Testing Your Application

## Fake the Service

The `google` adapter uses Laravel's HTTP client, so `Http::fake()` replaces the service:

```php
use Illuminate\Support\Facades\Http;

Http::fake(['maps.googleapis.com/*' => Http::response([
    'status' => 'OK',
    'results' => [['geometry' => ['location' => ['lat' => 52.5219184, 'lng' => 13.4132147]], 'place_id' => 'abc']],
])]);

$result = GeocodeCache::geocode(GeocodeQuery::make('Alexanderplatz 1', 'DE'));

Http::assertSentCount(1);
```

Use `['status' => 'ZERO_RESULTS', 'results' => []]` for an empty result and `['status' => 'REQUEST_DENIED']` for a failure.
The tests need the `geocode_cache` table, so use `RefreshDatabase` or `LazilyRefreshDatabase`, and a configured API key,
for example `config(['geocode-cache.drivers.google.api_key' => 'test'])`.

If you set a [connection of its own](configuration.md#a-connection-of-its-own) for the tables, those refresh traits
reset the default connection only. Use an in-memory SQLite connection for it in your tests, or add the connection to
`$connectionsToTransact` and drop its tables before `migrate:fresh`.

## Use Your Own Adapter

An adapter that returns fixed coordinates keeps HTTP out of your tests entirely:

```php
GeocodeCache::extend('fake', fn () => new FixedAdapter);
config(['geocode-cache.driver' => 'fake']);
```

## Travel in Time

Entries expire by the clock, so Laravel's time helpers work:

```php
GeocodeCache::geocode($query);

$this->travel(30)->days();

GeocodeCache::geocode($query); // the entry is expired, deleted and fetched again
```
