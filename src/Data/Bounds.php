<?php

declare(strict_types=1);

namespace Taldres\GeocodeCache\Data;

use InvalidArgumentException;

final readonly class Bounds
{
    public function __construct(
        public float $southWestLatitude,
        public float $southWestLongitude,
        public float $northEastLatitude,
        public float $northEastLongitude,
    ) {
        foreach ([$southWestLatitude, $northEastLatitude] as $latitude) {
            if ($latitude < -90 || $latitude > 90) {
                throw new InvalidArgumentException('A latitude must be between -90 and 90.');
            }
        }

        foreach ([$southWestLongitude, $northEastLongitude] as $longitude) {
            if ($longitude < -180 || $longitude > 180) {
                throw new InvalidArgumentException('A longitude must be between -180 and 180.');
            }
        }

        if ($southWestLatitude > $northEastLatitude) {
            throw new InvalidArgumentException('The south-west corner must not be north of the north-east corner.');
        }
    }

    /**
     * A canonical text of the corners, with a fixed number of decimals. It is part of the cache key, so it must not change
     * with the format of any service: adapters format the corners themselves.
     */
    public function toKey(): string
    {
        return sprintf(
            '%s,%s|%s,%s',
            $this->format($this->southWestLatitude),
            $this->format($this->southWestLongitude),
            $this->format($this->northEastLatitude),
            $this->format($this->northEastLongitude),
        );
    }

    /**
     * @return list<float>
     */
    public function toArray(): array
    {
        return [
            $this->southWestLatitude,
            $this->southWestLongitude,
            $this->northEastLatitude,
            $this->northEastLongitude,
        ];
    }

    private function format(float $value): string
    {
        return sprintf('%.7F', $value);
    }
}
