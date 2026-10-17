<?php

declare(strict_types=1);

namespace Taldres\GeocodeCache\Data;

use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Asks for a place by the ID that an earlier lookup returned. The ID is opaque: it is kept exactly as it is, so a
 * difference in case is a different place.
 */
final readonly class PlaceIdQuery
{
    /**
     * The length of the place_id column of the cache table.
     */
    public const int MAXIMUM_LENGTH = 512;

    /**
     * Bump it to invalidate all keys when the hashed payload changes.
     */
    private const int HASH_VERSION = 1;

    private const string TYPE = 'place_id';

    public string $placeId;

    public function __construct(string $placeId)
    {
        $placeId = Str::of($placeId)->trim()->toString();

        if (! Str::isMatch('/^[\x21-\x7E]{1,'.self::MAXIMUM_LENGTH.'}\z/', $placeId)) {
            throw new InvalidArgumentException('The place ID must be 1 to '.self::MAXIMUM_LENGTH.' printable characters without spaces.');
        }

        $this->placeId = $placeId;
    }

    public static function make(string $placeId): self
    {
        return new self($placeId);
    }

    /**
     * @param  array<array-key, mixed>  $payload
     */
    public static function fromArray(array $payload): self
    {
        $placeId = Arr::get($payload, 'place_id');

        if (Arr::get($payload, 'type') !== self::TYPE || ! is_string($placeId)) {
            throw new InvalidArgumentException('The payload is not a place ID query.');
        }

        return new self($placeId);
    }

    /**
     * @return array{type: string, place_id: string}
     */
    public function toArray(): array
    {
        return ['type' => self::TYPE, 'place_id' => $this->placeId];
    }

    public function hash(string $source): string
    {
        $payload = Arr::sortRecursive([
            'version' => self::HASH_VERSION,
            'source' => $source,
            ...$this->toArray(),
        ]);

        return hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}
