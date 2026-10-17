<?php

declare(strict_types=1);

namespace Taldres\GeocodeCache\Exceptions;

use Throwable;

/**
 * Implemented by the exceptions the package defines for a failed lookup, a misconfiguration or a query the adapter
 * cannot answer, so one catch block can handle them. Errors that Laravel raises itself are not among them.
 */
interface GeocodeCacheException extends Throwable {}
