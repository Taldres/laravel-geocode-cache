# AI Agents

The package ships a [Laravel Boost](https://laravel.com/docs/boost) skill for coding agents:

- `laravel-geocode-cache-development` teaches agents how to use the package, including the scheduler entry that is
  needed to delete expired entries.

Boost offers to install it when you run `php artisan boost:install`, or `php artisan boost:update --discover` in an
existing setup. It also takes over a short guideline that tells agents the package is installed and points them to the
skill. `boost:update` keeps both current.
