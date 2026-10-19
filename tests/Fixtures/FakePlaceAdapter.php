<?php

declare(strict_types=1);

namespace Taldres\GeocodeCache\Tests\Fixtures;

use Closure;
use Taldres\GeocodeCache\Contracts\Adapter;
use Taldres\GeocodeCache\Contracts\SupportsPlaceIdLookup;
use Taldres\GeocodeCache\Data\Coordinates;
use Taldres\GeocodeCache\Data\GeocodeQuery;
use Taldres\GeocodeCache\Data\PlaceIdQuery;

final class FakePlaceAdapter implements Adapter, SupportsPlaceIdLookup
{
    /**
     * @var list<PlaceIdQuery>
     */
    public array $placeQueries = [];

    /**
     * @var list<GeocodeQuery>
     */
    public array $queries = [];

    /**
     * @param  list<Coordinates|Closure(GeocodeQuery|PlaceIdQuery): ?Coordinates|null>  $responses
     */
    public function __construct(
        private array $responses = [],
        private readonly string $source = 'google',
        private readonly ?int $maximumRetentionDays = 29,
    ) {}

    /**
     * @param  Coordinates|Closure(GeocodeQuery|PlaceIdQuery): ?Coordinates|null  $response
     */
    public function queue(Coordinates|Closure|null $response): self
    {
        $this->responses[] = $response;

        return $this;
    }

    public function source(): string
    {
        return $this->source;
    }

    public function maximumRetentionDays(): ?int
    {
        return $this->maximumRetentionDays;
    }

    public function maximumPrecision(): int
    {
        return Coordinates::MAXIMUM_PRECISION;
    }

    public function geocode(GeocodeQuery $query): ?Coordinates
    {
        $this->queries[] = $query;

        return $this->next($query);
    }

    public function geocodePlaceId(PlaceIdQuery $query): ?Coordinates
    {
        $this->placeQueries[] = $query;

        return $this->next($query);
    }

    public function calls(): int
    {
        return count($this->placeQueries) + count($this->queries);
    }

    private function next(GeocodeQuery|PlaceIdQuery $query): ?Coordinates
    {
        $response = array_shift($this->responses);

        return $response instanceof Closure ? $response($query) : $response;
    }
}
