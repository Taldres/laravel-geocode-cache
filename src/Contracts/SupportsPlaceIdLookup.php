<?php

declare(strict_types=1);

namespace Taldres\GeocodeCache\Contracts;

use Taldres\GeocodeCache\Data\Coordinates;
use Taldres\GeocodeCache\Data\PlaceIdQuery;
use Taldres\GeocodeCache\Exceptions\GeocodingFailedException;

/**
 * Implemented by the adapters whose service can look a place up by its ID. An adapter without it only answers address
 * queries, and the cache refuses a place ID query for it instead of searching for something else.
 */
interface SupportsPlaceIdLookup
{
    /**
     * Returns null if the service does not know the place (anymore), so the application can look its address up instead.
     *
     * @throws GeocodingFailedException If the service could not be reached or rejected the request.
     */
    public function geocodePlaceId(PlaceIdQuery $query): ?Coordinates;
}
