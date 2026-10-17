<?php

declare(strict_types=1);

namespace Taldres\GeocodeCache\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Date;
use Taldres\GeocodeCache\Casts\UtcDateTime;
use Taldres\GeocodeCache\Support\Settings;

/**
 * @property int $id
 * @property string $query_hash
 * @property string $source
 * @property float $latitude
 * @property float $longitude
 * @property string|null $place_id
 * @property array<string, mixed>|null $query_payload
 * @property CarbonInterface $fetched_at
 * @property CarbonInterface $expires_at
 * @property CarbonInterface $last_requested_at
 */
class GeocodeCacheEntry extends Model
{
    use MassPrunable;

    public $timestamps = false;

    protected $fillable = [
        'query_hash',
        'source',
        'latitude',
        'longitude',
        'place_id',
        'query_payload',
        'fetched_at',
        'expires_at',
        'last_requested_at',
    ];

    public function getTable(): string
    {
        return Settings::string('geocode-cache.table');
    }

    /**
     * @param  Builder<static>  $query
     */
    public function scopeExpired(Builder $query): void
    {
        $query->where('expires_at', '<=', Date::now()->utc());
    }

    /**
     * @param  Builder<static>  $query
     */
    public function scopeValid(Builder $query): void
    {
        $query->where('expires_at', '>', Date::now()->utc());
    }

    /**
     * @return Builder<static>
     */
    public function prunable(): Builder
    {
        return static::query()->expired();
    }

    /**
     * @return array<string, class-string|string>
     */
    protected function casts(): array
    {
        return [
            'latitude' => 'float',
            'longitude' => 'float',
            'query_payload' => 'encrypted:array',
            'fetched_at' => UtcDateTime::class,
            'expires_at' => UtcDateTime::class,
            'last_requested_at' => UtcDateTime::class,
        ];
    }
}
