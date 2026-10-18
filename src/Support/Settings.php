<?php

declare(strict_types=1);

namespace Taldres\GeocodeCache\Support;

use InvalidArgumentException;
use Taldres\GeocodeCache\Exceptions\InvalidConfigurationException;

/**
 * Typed reads of the settings of the package. config()->string() and config()->integer() throw a plain
 * InvalidArgumentException of Laravel, which a catch block for the exceptions of the package would miss.
 *
 * @internal
 */
final class Settings
{
    /**
     * @throws InvalidConfigurationException
     */
    public static function string(string $key): string
    {
        try {
            return config()->string($key);
        } catch (InvalidArgumentException $exception) {
            throw new InvalidConfigurationException($exception->getMessage(), previous: $exception);
        }
    }

    /**
     * @throws InvalidConfigurationException
     */
    public static function integer(string $key): int
    {
        try {
            return config()->integer($key);
        } catch (InvalidArgumentException $exception) {
            throw new InvalidConfigurationException($exception->getMessage(), previous: $exception);
        }
    }

    /**
     * The connection of the tables of the package, or null for the default connection.
     *
     * @throws InvalidConfigurationException
     */
    public static function connection(): ?string
    {
        $connection = config('geocode-cache.connection');

        if ($connection === null || $connection === '') {
            return null;
        }

        if (! is_string($connection)) {
            throw new InvalidConfigurationException('The setting geocode-cache.connection must be the name of a database connection.');
        }

        return $connection;
    }
}
