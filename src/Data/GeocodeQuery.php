<?php

declare(strict_types=1);

namespace Taldres\GeocodeCache\Data;

use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Illuminate\Support\Stringable;
use InvalidArgumentException;

/**
 * Pass postal codes as strings ("01067", never 1067) so they keep their leading zeros.
 */
final readonly class GeocodeQuery
{
    /**
     * Bump it to invalidate all keys when the hashed payload changes.
     */
    private const int HASH_VERSION = 1;

    public string $search;

    public ?string $country;

    public ?string $language;

    public ?string $region;

    public function __construct(
        string $search,
        ?string $country = null,
        ?string $language = null,
        ?string $region = null,
        public ?Bounds $bounds = null,
    ) {
        $this->search = Str::of($search)->squish()->lower()->toString();
        $this->country = self::code($country, '/^[A-Za-z]{2}$/', 'country')?->upper()->toString();
        $this->language = self::code($language, '/^[A-Za-z]{2,3}(-[A-Za-z0-9]{2,8})*$/', 'language')?->lower()->toString();
        $this->region = self::code($region, '/^[A-Za-z]{2,3}$/', 'region')?->lower()->toString();

        if ($this->search === '') {
            throw new InvalidArgumentException('The search text must not be empty.');
        }
    }

    public static function make(
        string $search,
        ?string $country = null,
        ?string $language = null,
        ?string $region = null,
        ?Bounds $bounds = null,
    ): self {
        return new self($search, $country, $language, $region, $bounds);
    }

    /**
     * @param  array<array-key, mixed>  $payload
     */
    public static function fromArray(array $payload): self
    {
        $text = static fn (string $key): ?string => is_string($value = Arr::get($payload, $key)) ? $value : null;

        return new self(
            $text('search') ?? '',
            $text('country'),
            $text('language'),
            $text('region'),
            self::boundsFromArray(Arr::get($payload, 'bounds')),
        );
    }

    /**
     * @return array{search: string, country: ?string, language: ?string, region: ?string, bounds: ?list<float>}
     */
    public function toArray(): array
    {
        return [
            'search' => $this->search,
            'country' => $this->country,
            'language' => $this->language,
            'region' => $this->region,
            'bounds' => $this->bounds?->toArray(),
        ];
    }

    public function hash(string $source): string
    {
        $payload = Arr::sortRecursive([
            'version' => self::HASH_VERSION,
            'source' => $source,
            ...Arr::except($this->toArray(), ['bounds']),
            'bounds' => $this->bounds?->toKey(),
        ]);

        return hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private static function code(?string $value, string $pattern, string $name): ?Stringable
    {
        $value = Str::of((string) $value)->trim();

        if ($value->isEmpty()) {
            return null;
        }

        if (! $value->isMatch($pattern)) {
            throw new InvalidArgumentException("The {$name} [{$value}] is not valid.");
        }

        return $value;
    }

    private static function boundsFromArray(mixed $corners): ?Bounds
    {
        if (! is_array($corners) || count($corners) !== 4) {
            return null;
        }

        $values = [];

        foreach ($corners as $corner) {
            if (! is_numeric($corner)) {
                return null;
            }

            $values[] = (float) $corner;
        }

        return new Bounds(...$values);
    }
}
