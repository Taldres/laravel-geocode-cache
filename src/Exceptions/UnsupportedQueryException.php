<?php

declare(strict_types=1);

namespace Taldres\GeocodeCache\Exceptions;

use LogicException;

/**
 * The adapter cannot answer this kind of query, such as a place ID. It is thrown before the cache is read.
 */
class UnsupportedQueryException extends LogicException implements GeocodeCacheException {}
