<?php

declare(strict_types=1);

namespace Taldres\GeocodeCache\Facades;

use Illuminate\Support\Facades\Facade;
use Taldres\GeocodeCache\CachedGeocoder;
use Taldres\GeocodeCache\GeocodeCacheManager;

/**
 * @method static \Taldres\GeocodeCache\CachedGeocoder driver(string|null $driver = null)
 * @method static \Taldres\GeocodeCache\GeocodeCacheManager extend(string $driver, \Closure $callback)
 * @method static string getDefaultDriver()
 * @method static \Taldres\GeocodeCache\Contracts\Adapter adapter()
 * @method static bool supportsPlaceIds()
 * @method static bool supports(\Taldres\GeocodeCache\Data\GeocodeQuery|\Taldres\GeocodeCache\Data\PlaceIdQuery $query)
 * @method static \Taldres\GeocodeCache\Data\GeocodeResult|null geocode(\Taldres\GeocodeCache\Data\GeocodeQuery|\Taldres\GeocodeCache\Data\PlaceIdQuery|string $query)
 * @method static \Taldres\GeocodeCache\Enums\RefreshOutcome refresh(\Taldres\GeocodeCache\Data\GeocodeQuery|\Taldres\GeocodeCache\Data\PlaceIdQuery $query, \Carbon\CarbonInterface|null $onlyIfFetchedAt = null)
 * @method static int retentionDays()
 * @method static int precision()
 *
 * @see GeocodeCacheManager
 * @see CachedGeocoder
 */
class GeocodeCache extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return GeocodeCacheManager::class;
    }
}
