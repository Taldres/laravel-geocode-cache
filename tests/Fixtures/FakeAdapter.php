<?php

declare(strict_types=1);

namespace Taldres\GeocodeCache\Tests\Fixtures;

use Closure;
use Taldres\GeocodeCache\Contracts\Adapter;
use Taldres\GeocodeCache\Data\Coordinates;
use Taldres\GeocodeCache\Data\GeocodeQuery;

final class FakeAdapter implements Adapter
{
    /**
     * @var list<GeocodeQuery>
     */
    public array $queries = [];

    /**
     * @param  list<Coordinates|Closure(GeocodeQuery): ?Coordinates|null>  $responses
     */
    public function __construct(
        private array $responses = [],
        private readonly string $source = 'google',
        private readonly int $maximumPrecision = Coordinates::MAXIMUM_PRECISION,
        private readonly ?int $maximumRetentionDays = 30,
    ) {}

    public static function returning(float $latitude = 52.52, float $longitude = 13.405, ?string $placeId = 'place-1'): self
    {
        return new self([new Coordinates($latitude, $longitude, $placeId)]);
    }

    /**
     * @param  Coordinates|Closure(GeocodeQuery): ?Coordinates|null  $response
     */
    public function queue(Coordinates|Closure|null $response): self
    {
        $this->responses[] = $response;

        return $this;
    }

    public function maximumPrecision(): int
    {
        return $this->maximumPrecision;
    }

    public function maximumRetentionDays(): ?int
    {
        return $this->maximumRetentionDays;
    }

    public function source(): string
    {
        return $this->source;
    }

    public function geocode(GeocodeQuery $query): ?Coordinates
    {
        $this->queries[] = $query;

        $response = array_shift($this->responses);

        return $response instanceof Closure ? $response($query) : $response;
    }

    public function calls(): int
    {
        return count($this->queries);
    }
}
