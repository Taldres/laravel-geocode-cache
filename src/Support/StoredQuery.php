<?php

declare(strict_types=1);

namespace Taldres\GeocodeCache\Support;

use Illuminate\Support\Arr;
use InvalidArgumentException;
use Taldres\GeocodeCache\Data\GeocodeQuery;
use Taldres\GeocodeCache\Data\PlaceIdQuery;

/**
 * Rebuilds a query from the payload that the cache stores for the refresh.
 */
final class StoredQuery
{
    /**
     * A payload without a type is an address query: the entries stored before place IDs existed have no type.
     *
     * @param  array<array-key, mixed>  $payload
     *
     * @throws InvalidArgumentException
     */
    public static function fromArray(array $payload): GeocodeQuery|PlaceIdQuery
    {
        return match (Arr::get($payload, 'type')) {
            null => GeocodeQuery::fromArray($payload),
            'place_id' => PlaceIdQuery::fromArray($payload),
            default => throw new InvalidArgumentException('The stored query has an unknown type.'),
        };
    }
}
