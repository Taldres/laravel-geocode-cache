# Installation

## Requirements

- PHP 8.3 or higher
- Laravel 12 or 13
- SQLite, MySQL, MariaDB or PostgreSQL
- A cache store with atomic locks (`redis`, `database`, `file` or `memcached`) for the locks against parallel lookups
- A Google Geocoding API key

## Install the Package

```bash
composer require taldres/laravel-geocode-cache
```

Publish the configuration and the migration, then migrate. If the default table name `geocode_cache` does not fit, or the
tables belong on another database connection, change `table` or `connection` in the published configuration before you
migrate:

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

This step is required. The package never returns an expired entry, but only a command that runs regularly deletes the
entries nobody asks for again. Add it to `routes/console.php`:

```php
use Illuminate\Support\Facades\Schedule;

Schedule::command('geocode-cache:prune')->hourly()->onOneServer()->withoutOverlapping();
```

The default retention of 29 days leaves one day below the maximum of 30 days for results from Google. That day gives
the schedule room. It does not replace a scheduler that actually runs, so monitor it, for example with `pingOnFailure()`.
The scheduler itself needs its usual cron entry: `* * * * * cd /path-to-your-project && php artisan schedule:run`.

## Check the Setup

```bash
php artisan about --only=geocode_cache
```

It shows the driver, the table, the connection, the retention and whether the prune command is scheduled. Set `about` to
`false` in the configuration if you do not want the section.

## Laravel Boost

The package ships a [Laravel Boost](https://laravel.com/docs/boost) skill and a short guideline for coding agents.
`php artisan boost:install` offers them, `php artisan boost:update --discover` adds them to an existing setup, and
`boost:update` keeps them current.
