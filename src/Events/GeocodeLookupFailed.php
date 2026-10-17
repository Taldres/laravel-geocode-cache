<?php

declare(strict_types=1);

namespace Taldres\GeocodeCache\Events;

/**
 * Dispatched when the geocoding service could not be reached, reported an error or sent an unusable response, or
 * when the adapter threw something else. The reason is the fixed code of the GeocodingFailedException, or "unexpected". Like the log it never carries the searched text, the key
 * hash, coordinates or a place ID.
 */
final readonly class GeocodeLookupFailed
{
    public function __construct(public string $source, public string $reason) {}
}
