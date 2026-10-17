<?php

declare(strict_types=1);

namespace Taldres\GeocodeCache\Console\Commands;

use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;
use Taldres\GeocodeCache\Models\GeocodeCacheEntry;
use Taldres\GeocodeCache\Models\GeocodeCacheStatistic;
use Taldres\GeocodeCache\Support\GeocodeLog;

#[AsCommand(name: 'geocode-cache:prune', description: 'Delete the expired entries and the old statistics of the geocode cache')]
class PruneCommand extends Command
{
    protected $signature = 'geocode-cache:prune
                            {--pretend : Only report how many rows would be deleted}';

    public function handle(): int
    {
        if (config()->integer('geocode-cache.statistics.retention_days') < 1) {
            $this->components->error('The geocode-cache.statistics.retention_days setting must be 1 or more.');

            return self::FAILURE;
        }

        $entries = new GeocodeCacheEntry;
        $statistics = new GeocodeCacheStatistic;

        if ($this->option('pretend')) {
            $this->components->info($entries->prunable()->count().' expired geocode cache entries would be deleted.');
            $this->components->info($statistics->prunable()->count().' old geocode cache statistics rows would be deleted.');

            return self::SUCCESS;
        }

        $deleted = $entries->pruneAll();
        $oldStatistics = $statistics->pruneAll();

        GeocodeLog::write('info', 'Expired geocode cache entries were deleted.', ['deleted' => $deleted, 'statistics' => $oldStatistics]);
        $this->components->info($deleted.' expired geocode cache entries deleted.');
        $this->components->info($oldStatistics.' old geocode cache statistics rows deleted.');

        return self::SUCCESS;
    }
}
