<?php

declare(strict_types=1);

namespace Taldres\GeocodeCache\Support;

use Illuminate\Database\Connection;
use InvalidArgumentException;
use Taldres\GeocodeCache\Exceptions\InvalidConfigurationException;

/**
 * Puts a model on the connection of the geocode-cache.connection setting, unless the model names one itself.
 *
 * @internal
 */
trait UsesConfiguredConnection
{
    public function getConnectionName(): ?string
    {
        return parent::getConnectionName() ?? Settings::connection();
    }

    /**
     * The name is not checked against config/database.php, because a connection may also be registered at runtime and
     * its name may contain a dot. Laravel reports an unknown one with an InvalidArgumentException, which a catch block
     * for the exceptions of the package would miss.
     *
     * @throws InvalidConfigurationException
     */
    public function getConnection(): Connection
    {
        try {
            return parent::getConnection();
        } catch (InvalidArgumentException $exception) {
            throw new InvalidConfigurationException($exception->getMessage(), previous: $exception);
        }
    }
}
