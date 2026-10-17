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
}
