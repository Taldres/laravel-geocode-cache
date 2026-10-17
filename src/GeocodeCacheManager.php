<?php

declare(strict_types=1);

namespace Taldres\GeocodeCache;

use Illuminate\Support\Manager;
use Illuminate\Support\Str;
use Taldres\GeocodeCache\Adapters\GoogleAdapter;
use Taldres\GeocodeCache\Contracts\Adapter;
use Taldres\GeocodeCache\Exceptions\InvalidConfigurationException;
use Taldres\GeocodeCache\Support\Settings;

/**
 * @mixin CachedGeocoder
 */
class GeocodeCacheManager extends Manager
{
    public function getDefaultDriver(): string
    {
        return Settings::string('geocode-cache.driver');
    }

    public function driver($driver = null): CachedGeocoder
    {
        $name = $driver ?? $this->getDefaultDriver();

        if (! is_string($name) || (! isset($this->customCreators[$name]) && ! method_exists($this, 'create'.Str::studly($name).'Driver'))) {
            throw new InvalidConfigurationException('Driver ['.(is_string($name) ? $name : get_debug_type($name)).'] not supported.');
        }

        $geocoder = parent::driver($name);

        if (! $geocoder instanceof CachedGeocoder) {
            throw new InvalidConfigurationException('The driver must return a CachedGeocoder or an Adapter.');
        }

        return $geocoder;
    }

    protected function createGoogleDriver(): CachedGeocoder
    {
        $config = $this->driverConfig('google');

        return new CachedGeocoder(new GoogleAdapter(
            $this->apiKey($config),
            is_string($config['endpoint'] ?? null) ? $config['endpoint'] : 'https://maps.googleapis.com/maps/api/geocode/json',
            $this->timeout($config),
        ));
    }

    protected function callCustomCreator($driver): mixed
    {
        $created = parent::callCustomCreator($driver);

        return $created instanceof Adapter ? new CachedGeocoder($created) : $created;
    }

    /**
     * @return array<array-key, mixed>
     */
    private function driverConfig(string $driver): array
    {
        $config = config("geocode-cache.drivers.{$driver}");

        return is_array($config) ? $config : [];
    }

    /**
     * @param  array<array-key, mixed>  $config
     *
     * @throws InvalidConfigurationException
     */
    private function timeout(array $config): int
    {
        $timeout = is_int($config['timeout'] ?? null) ? $config['timeout'] : 5;
        $lifetime = config('geocode-cache.lock.seconds');

        // A lock that frees itself while the service still answers would let a second process ask for the same query.
        // An invalid lifetime is reported by the lock itself.
        if (is_int($lifetime) && $lifetime >= 1 && $timeout >= $lifetime) {
            throw new InvalidConfigurationException('The driver timeout must be shorter than geocode-cache.lock.seconds.');
        }

        return $timeout;
    }

    /**
     * @param  array<array-key, mixed>  $config
     */
    private function apiKey(array $config): string
    {
        return is_string($config['api_key'] ?? null) ? $config['api_key'] : '';
    }
}
