<?php

declare(strict_types=1);

namespace Taldres\GeocodeCache\Events;

/**
 * Dispatched after the geocoding service answered, with or without a result. Like the log it never carries the
 * searched text, the key hash, coordinates or a place ID.
 */
final readonly class GeocodeServiceCalled
{
    public function __construct(public string $source, public bool $found) {}
}
