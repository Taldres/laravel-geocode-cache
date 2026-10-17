<?php

declare(strict_types=1);

namespace Taldres\GeocodeCache\Tests\Fixtures;

use Taldres\GeocodeCache\Contracts\Adapter;
use Taldres\GeocodeCache\Data\Coordinates;
use Taldres\GeocodeCache\Data\GeocodeQuery;

/**
 * An adapter for processes that run side by side: it takes a while, like a real service, and writes one line per call
 * to a file that all of them share.
 */
final readonly class CountingAdapter implements Adapter
{
    public function __construct(private string $callsFile, private int $milliseconds = 800) {}

    public function source(): string
    {
        return 'google';
    }

    public function maximumPrecision(): int
    {
        return Coordinates::MAXIMUM_PRECISION;
    }

    public function maximumRetentionDays(): ?int
    {
        return 30;
    }

    public function geocode(GeocodeQuery $query): ?Coordinates
    {
        file_put_contents($this->callsFile, $query->hash('google')."\n", FILE_APPEND | LOCK_EX);
        usleep($this->milliseconds * 1_000);

        return new Coordinates(52.52, 13.405);
    }
}
