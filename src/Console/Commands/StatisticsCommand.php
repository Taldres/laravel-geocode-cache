<?php

declare(strict_types=1);

namespace Taldres\GeocodeCache\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Str;
use Symfony\Component\Console\Attribute\AsCommand;
use Taldres\GeocodeCache\Models\GeocodeCacheStatistic;

#[AsCommand(name: 'geocode-cache:statistics', description: 'Show how often and how regularly query hashes were requested')]
class StatisticsCommand extends Command
{
    protected $signature = 'geocode-cache:statistics
                            {--days=30 : The number of days to look back, from 1 to 3650}
                            {--limit=20 : The number of hashes to show, from 1 to 1000}';

    public function handle(): int
    {
        $days = $this->number('days', 3650);
        $limit = $this->number('limit', 1000);

        if ($days === null || $limit === null) {
            $this->components->error('The --days option must be a whole number from 1 to 3650 and --limit from 1 to 1000.');

            return self::FAILURE;
        }

        if (! config('geocode-cache.statistics.enabled')) {
            $this->components->warn('The statistics are off. Set geocode-cache.statistics.enabled to true to count requests.');
        }

        // The window includes today, so 30 days are today and the 29 days before.
        $since = Date::now()->utc()->subDays($days - 1)->toDateString();

        $rows = GeocodeCacheStatistic::query()
            ->toBase()
            ->where('date', '>=', $since)
            ->selectRaw('query_hash, count(*) as active_days, sum(hits) as hits, sum(lookups) as lookups, sum(refreshes) as refreshes, min(date) as first_day, max(date) as last_day')
            ->groupBy('query_hash')
            ->orderByRaw('sum(hits) + sum(lookups) desc')
            ->orderBy('query_hash')
            ->limit($limit)
            ->get();

        if ($rows->isEmpty()) {
            $this->components->info("No requests were counted in the last {$days} days.");

            return self::SUCCESS;
        }

        $this->table(
            ['Hash', 'Active days', 'Hits', 'Lookups', 'Refreshes', 'First day', 'Last day'],
            $rows->map(fn (object $row): array => [
                Str::limit($this->text($row, 'query_hash'), 12, ''),
                "{$this->text($row, 'active_days')} of {$days}",
                $this->text($row, 'hits'),
                $this->text($row, 'lookups'),
                $this->text($row, 'refreshes'),
                $this->text($row, 'first_day'),
                $this->text($row, 'last_day'),
            ])->all(),
        );

        $total = GeocodeCacheStatistic::query()->toBase()->where('date', '>=', $since)->distinct()->count('query_hash');
        $this->components->info("{$rows->count()} of {$total} hashes shown, most requested first. Active days counts the days with at least one request.");

        return self::SUCCESS;
    }

    private function number(string $option, int $maximum): ?int
    {
        $value = $this->option($option);
        $value = is_int($value) ? (string) $value : $value;

        if (! is_string($value) || ! Str::isMatch('/^\d{1,4}\z/', $value) || (int) $value < 1 || (int) $value > $maximum) {
            return null;
        }

        return (int) $value;
    }

    private function text(object $row, string $column): string
    {
        $value = $row->{$column} ?? '';

        return is_scalar($value) ? (string) $value : '';
    }
}
