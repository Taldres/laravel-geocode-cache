<?php

declare(strict_types=1);

namespace Taldres\GeocodeCache\Adapters;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Taldres\GeocodeCache\Contracts\Adapter;
use Taldres\GeocodeCache\Contracts\SupportsPlaceIdLookup;
use Taldres\GeocodeCache\Data\Bounds;
use Taldres\GeocodeCache\Data\Coordinates;
use Taldres\GeocodeCache\Data\GeocodeQuery;
use Taldres\GeocodeCache\Data\PlaceIdQuery;
use Taldres\GeocodeCache\Exceptions\GeocodingFailedException;
use Taldres\GeocodeCache\Exceptions\InvalidConfigurationException;

final readonly class GoogleAdapter implements Adapter, SupportsPlaceIdLookup
{
    /**
     * Google allows the temporary storage of coordinates for up to 30 consecutive calendar days. The maximum is one
     * day lower, so the scheduled prune deletes an entry before the 30 days are over.
     */
    public const int MAXIMUM_RETENTION_DAYS = 29;

    public function __construct(
        private string $apiKey,
        private string $endpoint = 'https://maps.googleapis.com/maps/api/geocode/json',
        private int $timeout = 5,
    ) {
        if ($apiKey === '') {
            throw new InvalidConfigurationException('The Google Geocoding API key is not set.');
        }
    }

    public function source(): string
    {
        return 'google';
    }

    public function maximumPrecision(): int
    {
        // Google delivers seven decimals, about a centimetre.
        return Coordinates::MAXIMUM_PRECISION;
    }

    public function maximumRetentionDays(): int
    {
        return self::MAXIMUM_RETENTION_DAYS;
    }

    public function geocode(GeocodeQuery $query): ?Coordinates
    {
        return $this->request($this->parameters($query), ['ZERO_RESULTS']);
    }

    public function geocodePlaceId(PlaceIdQuery $query): ?Coordinates
    {
        // A place ID that is no longer valid comes back as ZERO_RESULTS. A malformed one is a bad request, HTTP 400.
        return $this->request(['place_id' => $query->placeId, 'key' => $this->apiKey], ['ZERO_RESULTS']);
    }

    /**
     * @param  array<string, string>  $parameters
     * @param  list<string>  $statusesWithoutResult
     */
    private function request(array $parameters, array $statusesWithoutResult): ?Coordinates
    {
        try {
            $response = Http::timeout($this->timeout)
                ->acceptJson()
                ->get($this->endpoint, $parameters);
        } catch (ConnectionException) {
            // The exception message holds the URL, with the API key and the searched address.
            throw GeocodingFailedException::connection();
        }

        if ($response->failed()) {
            // Google reports a bad request as HTTP 400 and puts the reason into the status of the body.
            $reason = $response->json('status');

            throw $response->clientError() && is_string($reason)
                ? GeocodingFailedException::serviceStatus($reason)
                : GeocodingFailedException::httpStatus($response->status());
        }

        $status = $response->json('status');

        if (is_string($status) && Collection::make($statusesWithoutResult)->containsStrict($status)) {
            return null;
        }

        return match ($status) {
            'OK' => Coordinates::fromResponse(
                $response->json('results.0.geometry.location.lat'),
                $response->json('results.0.geometry.location.lng'),
                $response->json('results.0.place_id'),
            ),
            default => throw GeocodingFailedException::serviceStatus(is_string($status) ? $status : 'UNKNOWN'),
        };
    }

    /**
     * @return array<string, string>
     */
    private function parameters(GeocodeQuery $query): array
    {
        return Arr::whereNotNull([
            'address' => $query->search,
            'components' => $query->country !== null ? "country:{$query->country}" : null,
            'language' => $query->language,
            'region' => $query->region,
            'bounds' => $query->bounds !== null ? $this->bounds($query->bounds) : null,
            'key' => $this->apiKey,
        ]);
    }

    /**
     * South-west and north-east corner, the way Google expects them.
     */
    private function bounds(Bounds $bounds): string
    {
        return sprintf(
            '%.7F,%.7F|%.7F,%.7F',
            $bounds->southWestLatitude,
            $bounds->southWestLongitude,
            $bounds->northEastLatitude,
            $bounds->northEastLongitude,
        );
    }
}
