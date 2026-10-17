<?php

declare(strict_types=1);

namespace Taldres\GeocodeCache\Casts;

use Carbon\CarbonInterface;
use DateTimeInterface;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Date;

/**
 * @implements CastsAttributes<CarbonInterface, DateTimeInterface|string>
 */
final class UtcDateTime implements CastsAttributes
{
    private const string FORMAT = 'Y-m-d H:i:s';

    public function get(Model $model, string $key, mixed $value, array $attributes): ?CarbonInterface
    {
        if (! is_string($value)) {
            return null;
        }

        return Date::parse($value, 'UTC')->setTimezone(config()->string('app.timezone'));
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        $moment = $value instanceof DateTimeInterface ? Date::instance($value) : Date::parse((string) $value);

        return $moment->utc()->format(self::FORMAT);
    }
}
