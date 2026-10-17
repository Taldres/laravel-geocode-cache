<?php

declare(strict_types=1);

namespace Taldres\GeocodeCache\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Date;
use InvalidArgumentException;
use Taldres\GeocodeCache\Support\Settings;

/**
 * @property int $id
 * @property string $query_hash
 * @property string $date The UTC day, Y-m-d.
 * @property int $hits
 * @property int $lookups
 * @property int $refreshes
 */
class GeocodeCacheStatistic extends Model
{
    use MassPrunable;

    public const array COUNTERS = ['hits', 'lookups', 'refreshes'];

    public $timestamps = false;

    protected $fillable = ['query_hash', 'date', 'hits', 'lookups', 'refreshes'];

    public function getTable(): string
    {
        return Settings::string('geocode-cache.statistics.table');
    }

    public static function record(string $hash, string $counter): void
    {
        if (! Collection::make(self::COUNTERS)->containsStrict($counter)) {
            throw new InvalidArgumentException("Unknown counter [{$counter}].");
        }

        $day = Date::now()->utc()->toDateString();
        $row = static fn (): Builder => self::query()->where('query_hash', $hash)->where('date', $day);

        if ($row()->increment($counter) > 0) {
            return;
        }

        $initial = Collection::make(self::COUNTERS)->mapWithKeys(fn (string $name): array => [$name => $name === $counter ? 1 : 0])->all();

        // A parallel request may have created the row since: then the insert is ignored and the counter is added.
        if (self::query()->insertOrIgnore([['query_hash' => $hash, 'date' => $day, ...$initial]]) === 0) {
            $row()->increment($counter);
        }
    }

    /**
     * @return Builder<static>
     */
    public function prunable(): Builder
    {
        return static::query()->where('date', '<', Date::now()->utc()->subDays(Settings::integer('geocode-cache.statistics.retention_days'))->toDateString());
    }
}
