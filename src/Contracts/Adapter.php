<?php

declare(strict_types=1);

namespace Taldres\GeocodeCache\Contracts;

use Taldres\GeocodeCache\Data\Coordinates;
use Taldres\GeocodeCache\Data\GeocodeQuery;
use Taldres\GeocodeCache\Exceptions\GeocodingFailedException;

interface Adapter
{
    /**
     * The data provider the adapter reads from, for example "google". It is stored with every cache entry and
     * is part of the query hash, so two adapters that read from the same provider share their entries.
     */
    public function source(): string;

    /**
     * The most decimals of a coordinate the service delivers. The `precision` setting cannot exceed it, and the
     * cache table stores seven decimals at most.
     */
    public function maximumPrecision(): int;

    /**
     * The most days a result of this adapter may be kept, counted from the fetch, or null if its source sets no limit.
     * The retention of the cache never exceeds it, whatever the configuration says, and an entry that was stored for
     * longer is not returned. Adapters with the same source must report the same value, because they share their entries.
     */
    public function maximumRetentionDays(): ?int;

    /**
     * Looks up the coordinates of the query. Returns null if the service found nothing for it.
     *
     * @throws GeocodingFailedException If the service could not be reached or reported an error.
     */
    public function geocode(GeocodeQuery $query): ?Coordinates;
}
