<?php

declare(strict_types=1);

namespace Taldres\GeocodeCache\Exceptions;

use InvalidArgumentException;

/**
 * A setting, a driver or an adapter of the application is unusable. It is thrown before the service is called.
 */
class InvalidConfigurationException extends InvalidArgumentException implements GeocodeCacheException {}
