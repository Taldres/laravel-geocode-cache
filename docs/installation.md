# Installation

## Requirements

- PHP 8.3 or higher
- Laravel 12 or 13
- A database that supports Eloquent upserts: SQLite, MySQL, MariaDB or PostgreSQL. The test workflow targets SQLite, MySQL,
  MariaDB and PostgreSQL.
- A cache store that supports atomic locks, such as `redis`, `database`, `file` or `memcached`, for the locks against
  parallel lookups
- A Google Geocoding API key

## Install the Package

```bash
composer require taldres/laravel-geocode-cache
```

## Publish and Migrate

If the default table name `geocode_cache` does not fit, publish the configuration and change `table` first. Then
publish the migration and migrate:

```bash
php artisan vendor:publish --tag=geocode-cache
php artisan migrate
```

The tags `geocode-cache-config` and `geocode-cache-migrations` publish the two parts separately.

## Set the API Key

```
GOOGLE_MAPS_GEOCODING_API_KEY=your-key
```

Restrict the key in the Google Cloud console to the Geocoding API and to the IP addresses of your servers.

## Schedule the Pruning

This step is required. The package never returns an expired entry, but it can only delete entries nobody asks for again
with a command that runs regularly. Add it to `routes/console.php`:

```php
use Illuminate\Support\Facades\Schedule;

Schedule::command('geocode-cache:prune')->hourly()->onOneServer()->withoutOverlapping();
```

The same command deletes statistics rows that are older than `statistics.retention_days`. The default retention of 29 days leaves one day below the maximum of 30 days for results from Google. That distance gives the schedule room,
it does not replace a scheduler that actually runs, so monitor it. Laravel offers `pingOnFailure` and similar hooks for
that.

The scheduler itself needs its usual cron entry: `* * * * * cd /path-to-your-project && php artisan schedule:run`.

## Check the Setup

```bash
php artisan about --only=geocode_cache
```

It shows the driver, the table, the retention and whether the prune command is scheduled.
