<?php

declare(strict_types=1);

namespace Taldres\GeocodeCache\Data;

use Carbon\CarbonInterface;

final readonly class GeocodeResult
{
    public function __construct(
        public float $latitude,
        public float $longitude,
        public ?string $placeId,
        public string $source,
        public CarbonInterface $fetchedAt,
        public CarbonInterface $expiresAt,
    ) {}
}
