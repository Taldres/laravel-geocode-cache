<?php

declare(strict_types=1);

namespace Taldres\GeocodeCache\Data;

use InvalidArgumentException;
use Taldres\GeocodeCache\Exceptions\GeocodingFailedException;

final readonly class Coordinates
{
    /**
     * The decimals the cache table stores, which is also what the Google Geocoding API delivers at most.
     */
    public const int MAXIMUM_PRECISION = 7;

    public float $latitude;

    public float $longitude;

    public function __construct(float $latitude, float $longitude, public ?string $placeId = null)
    {
        if ($latitude < -90 || $latitude > 90 || $longitude < -180 || $longitude > 180) {
            throw new InvalidArgumentException('The coordinates are out of range.');
        }

        // The cache column holds seven decimals, so a fresh result equals the one read back later.
        $this->latitude = round($latitude, self::MAXIMUM_PRECISION);
        $this->longitude = round($longitude, self::MAXIMUM_PRECISION);
    }

    /**
     * @throws GeocodingFailedException
     */
    public static function fromResponse(mixed $latitude, mixed $longitude, mixed $placeId = null): self
    {
        if (! is_numeric($latitude) || ! is_numeric($longitude)) {
            throw GeocodingFailedException::malformedResponse();
        }

        try {
            return new self(
                (float) $latitude,
                (float) $longitude,
                is_string($placeId) && $placeId !== '' ? $placeId : null,
            );
        } catch (InvalidArgumentException) {
            throw GeocodingFailedException::malformedResponse();
        }
    }
}
