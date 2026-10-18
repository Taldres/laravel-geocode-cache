<?php

declare(strict_types=1);

return [
    /*
     * The driver that looks coordinates up when the cache has no valid entry: "google" calls the Google Geocoding
     * API directly. Register another service as a driver with GeocodeCache::extend().
     */
    'driver' => env('GEOCODE_CACHE_DRIVER', 'google'),

    /*
     * The name of the table that holds the cached coordinates. Set it before you publish and run the migration.
     */
    'table' => env('GEOCODE_CACHE_TABLE', 'geocode_cache'),

    /*
     * The database connection of the cache table and the statistics table, a name from config/database.php. Leave
     * it empty for the default connection. A connection of its own keeps the cache out of the transactions of your
     * application and, with a tenant database as the default, lets all tenants share one cache. Set it before you
     * publish and run the migrations.
     */
    'connection' => env('GEOCODE_CACHE_DB_CONNECTION'),

    /*
     * The number of days a result may be used and kept, counted from the moment it was fetched from the service.
     * Reading an entry never extends it. Google allows the temporary storage of coordinates for up to 30
     * consecutive calendar days, so values above 30 are rejected for results from Google. The default of 29 days
     * leaves a day for the scheduled geocode-cache:prune command. Check the terms that apply to your contract.
     */
    'ttl_days' => (int) env('GEOCODE_CACHE_TTL_DAYS', 29),

    /*
     * The retention of a data source that differs from ttl_days, keyed by the source of the adapter, for example
     * ['mapbox' => 365]. The retention is a matter of the terms of the provider, so it belongs to the source and not to
     * a driver: drivers with the same source share their entries. The adapter can set a maximum for its source (Google:
     * 30 days) and a value above it is rejected.
     */
    'source_ttl_days' => [],

    /*
     * The decimals of the latitude and the longitude that are stored and returned. Fewer decimals mean a coarser
     * position: 4 decimals are about 11 metres, 2 are about 1.1 kilometres. The maximum is what the adapter of the driver delivers,
     * which is 7 decimals for Google.
     * Leave it empty for the maximum. Values above it are rejected.
     */
    'precision' => in_array($precision = env('GEOCODE_CACHE_PRECISION'), [null, ''], true)
        ? null
        : (is_numeric($precision) ? (int) $precision : $precision),

    /*
     * Whether the place ID of a result is stored next to the coordinates. Google lets you keep place IDs
     * indefinitely, but they are removed with the entry here. With refresh.store_query, the ID that a place ID query asks
     * for is kept encrypted in the stored query regardless of this setting.
     */
    'store_place_id' => (bool) env('GEOCODE_CACHE_STORE_PLACE_ID', true),

    'drivers' => [
        'google' => [
            'api_key' => env('GOOGLE_MAPS_GEOCODING_API_KEY'),
            'endpoint' => 'https://maps.googleapis.com/maps/api/geocode/json',
            // Seconds to wait for the service. It must stay below lock.seconds.
            'timeout' => 5,
        ],
    ],

    /*
     * One lock per query stops parallel requests from asking the service for the same query twice.
     * The lock needs a cache store that supports atomic locks, such as redis, database, file or memcached.
     */
    'lock' => [
        // The cache store that holds the locks. Null uses the default store of the application.
        'store' => env('GEOCODE_CACHE_LOCK_STORE'),

        // Seconds until a lock frees itself, in case the process that holds it dies. It must exceed the timeout of
        // the driver, otherwise a second process could start while the first one is still waiting for the service.
        // A driver whose timeout reaches it is rejected.
        'seconds' => 30,

        // Seconds a request waits for another process that looks up the same query.
        'wait' => 10,
    ],

    /*
     * Counters that show how often and how regularly each query hash is requested: per hash and UTC day, how many
     * requests the cache answered (hits), how many asked the service (lookups) and how many calls the refresh made.
     * They hold no query, no coordinates and no place IDs, and the geocode-cache:statistics command shows them.
     * Every request writes one row, so they are off by default. The prune command deletes rows older than
     * retention_days. Keep in mind that a hash can be confirmed against a known address.
     */
    'statistics' => [
        'enabled' => (bool) env('GEOCODE_CACHE_STATISTICS_ENABLED', false),

        'table' => env('GEOCODE_CACHE_STATISTICS_TABLE', 'geocode_cache_statistics'),

        'retention_days' => (int) env('GEOCODE_CACHE_STATISTICS_RETENTION_DAYS', 90),
    ],

    /*
     * Whether the package adds a "Geocode Cache" section to `php artisan about`. It shows the driver, the table, the
     * retention and whether the prune command is scheduled. Switch it off if you do not want it in that output.
     */
    'about' => (bool) env('GEOCODE_CACHE_ABOUT', true),

    /*
     * An optional log of failed lookups, lock timeouts and the runs of the prune and refresh commands.
     * Name a channel of config/logging.php to switch it on, leave it empty to switch it off.
     * The log never contains the searched text, the key hash, coordinates, place IDs or the API key.
     */
    'logging' => [
        'channel' => env('GEOCODE_CACHE_LOG_CHANNEL'),
    ],

    /*
     * The geocode-cache:refresh command can fetch entries again shortly before they expire, so that queries
     * which are still in use do not hit an empty cache. A refresh asks for the place ID of an entry. With store_query
     * it asks for the address instead, which then is stored encrypted with the application key. The time the entry
     * was last requested is stored in both cases.
     * Entries nobody requests anymore are never refreshed. They expire and are pruned.
     */
    'refresh' => [
        'enabled' => (bool) env('GEOCODE_CACHE_REFRESH_ENABLED', false),

        // Whether the query is stored too, encrypted. With it a refresh asks for the address again. Without it only the
        // place ID is stored, and an entry is refreshed through its place ID. That keeps the searched text out of the
        // table, but needs store_place_id and an adapter that can look a place ID up.
        'store_query' => (bool) env('GEOCODE_CACHE_REFRESH_STORE_QUERY', false),

        // Entries that expire within this many days are refreshed.
        'expiring_within_days' => 3,

        // Entries that were not requested during this many days are not refreshed.
        'requested_within_days' => 14,

        // Seconds between two writes of the last request time of an entry, to keep reads cheap.
        'touch_interval' => 3600,

        // The maximum number of service calls of one run, to cap the cost. Entries of the same place share a call.
        'limit' => 100,
    ],
];
