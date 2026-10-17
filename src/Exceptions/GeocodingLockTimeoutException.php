<?php

declare(strict_types=1);

namespace Taldres\GeocodeCache\Exceptions;

/**
 * Another process still looks up the same query. Nothing is wrong with the service, so the call can be tried again.
 */
class GeocodingLockTimeoutException extends GeocodingFailedException
{
    public function __construct()
    {
        parent::__construct('Another process is still looking up the same query. Try again in a moment.', 'lock_timeout');
    }
}
