<?php

declare(strict_types=1);

namespace Taldres\GeocodeCache;

use Closure;
use Composer\InstalledVersions;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\AboutCommand;
use Illuminate\Support\Collection;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Taldres\GeocodeCache\Console\Commands\PruneCommand;
use Taldres\GeocodeCache\Console\Commands\RefreshCommand;
use Taldres\GeocodeCache\Console\Commands\StatisticsCommand;
use Throwable;

class GeocodeCacheServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Recursive, so a configuration published earlier still gets the nested keys that later versions add.
        $this->replaceConfigRecursivelyFrom(__DIR__.'/../config/geocode-cache.php', 'geocode-cache');

        $this->app->singleton(GeocodeCacheManager::class);
    }

    public function boot(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->commands([PruneCommand::class, RefreshCommand::class, StatisticsCommand::class]);

        $this->publishes([
            __DIR__.'/../config/geocode-cache.php' => config_path('geocode-cache.php'),
        ], ['geocode-cache-config', 'geocode-cache']);

        $this->publishesMigrations([
            __DIR__.'/../database/migrations' => database_path('migrations'),
        ], ['geocode-cache-migrations', 'geocode-cache']);

        if (config('geocode-cache.about', true)) {
            $this->provideAboutSection();
        }
    }

    private function provideAboutSection(): void
    {
        AboutCommand::add('Geocode Cache', function (): array {
            $driver = config('geocode-cache.driver');
            $days = $this->driverValue(fn (CachedGeocoder $geocoder): int => $geocoder->retentionDays()) ?? config('geocode-cache.ttl_days');
            $refresh = (bool) config('geocode-cache.refresh.enabled');

            return [
                'Version' => InstalledVersions::isInstalled('taldres/laravel-geocode-cache')
                    ? InstalledVersions::getPrettyVersion('taldres/laravel-geocode-cache')
                    : null,
                'Driver' => AboutCommand::format($driver, console: fn (): string => is_string($driver) ? $driver : '<fg=red;options=bold>INVALID</>'),
                'Table' => AboutCommand::format(config('geocode-cache.table')),
                'Connection' => AboutCommand::format(config('geocode-cache.connection'), console: fn (): string => is_string(config('geocode-cache.connection')) && config('geocode-cache.connection') !== ''
                    ? (string) config('geocode-cache.connection')
                    : '<fg=gray>DEFAULT</>'),
                'Retention' => AboutCommand::format($days, console: fn (): string => $this->driverLabel(fn (CachedGeocoder $geocoder): string => $geocoder->retentionDays().' days')),
                'Precision' => AboutCommand::format(config('geocode-cache.precision'), console: fn (): string => $this->driverLabel(fn (CachedGeocoder $geocoder): string => $geocoder->precision().' decimals')),
                'Statistics' => AboutCommand::format((bool) config('geocode-cache.statistics.enabled'), console: fn (): string => config('geocode-cache.statistics.enabled')
                    ? '<fg=green;options=bold>ON</>'
                    : '<fg=gray>OFF</>'),
                'Logging' => AboutCommand::format(config('geocode-cache.logging.channel'), console: fn (): string => is_string(config('geocode-cache.logging.channel')) && config('geocode-cache.logging.channel') !== ''
                    ? (string) config('geocode-cache.logging.channel')
                    : '<fg=gray>OFF</>'),
                'Prune Scheduled' => AboutCommand::format($this->isScheduled('geocode-cache:prune'), console: fn (): string => $this->isScheduled('geocode-cache:prune')
                    ? '<fg=green;options=bold>YES</>'
                    : '<fg=red;options=bold>NO, EXPIRED ENTRIES STAY IN THE TABLE</>'),
                'Refresh' => AboutCommand::format($refresh, console: fn (): string => match (true) {
                    ! $refresh => '<fg=gray>OFF</>',
                    ! config('geocode-cache.refresh.store_query') && ! config('geocode-cache.store_place_id') => '<fg=red;options=bold>ENABLED, NOTHING TO REFRESH WITH</>',
                    ! config('geocode-cache.refresh.store_query') && ! $this->defaultDriverSupportsPlaceIds() => '<fg=red;options=bold>ENABLED, ADAPTER CANNOT LOOK PLACE IDS UP</>',
                    $this->isScheduled('geocode-cache:refresh') => '<fg=green;options=bold>ENABLED, SCHEDULED</>',
                    default => '<fg=yellow;options=bold>ENABLED, NOT SCHEDULED</>',
                }),
            ];
        });
    }

    private function defaultDriverSupportsPlaceIds(): bool
    {
        try {
            return $this->app->make(GeocodeCacheManager::class)->driver()->supportsPlaceIds();
        } catch (Throwable) {
            // An unusable driver is reported elsewhere, so it is not a reason to warn here.
            return true;
        }
    }

    /**
     * The value of a setting as it applies to the default driver, or null if the driver or the setting is unusable.
     *
     * @param  Closure(CachedGeocoder): int  $read
     */
    private function driverValue(Closure $read): ?int
    {
        try {
            return $read($this->app->make(GeocodeCacheManager::class)->driver());
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Describes a setting the way it applies to the default driver: with the maximum of its adapter.
     *
     * @param  Closure(CachedGeocoder): string  $describe
     */
    private function driverLabel(Closure $describe): string
    {
        try {
            $geocoder = $this->app->make(GeocodeCacheManager::class)->driver();
        } catch (Throwable) {
            return '<fg=yellow;options=bold>UNKNOWN, NO USABLE DRIVER</>';
        }

        try {
            return $describe($geocoder);
        } catch (Throwable) {
            return '<fg=red;options=bold>INVALID</>';
        }
    }

    private function isScheduled(string $command): bool
    {
        try {
            return Collection::make($this->app->make(Schedule::class)->events())
                ->contains(fn (Event $event): bool => Str::contains((string) $event->command, $command));
        } catch (Throwable) {
            return false;
        }
    }
}
