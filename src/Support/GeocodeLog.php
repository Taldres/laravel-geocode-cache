<?php

declare(strict_types=1);

namespace Taldres\GeocodeCache\Support;

use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Callers must never pass the searched text, the key hash, coordinates, place IDs or the API key: logs are copies the
 * package cannot delete.
 */
final class GeocodeLog
{
    /**
     * @param  array<string, bool|int|string|null>  $context
     */
    public static function write(string $level, string $message, array $context = []): void
    {
        $channel = config('geocode-cache.logging.channel');

        if (! is_string($channel) || $channel === '') {
            return;
        }

        try {
            Log::channel($channel)->log($level, $message, $context);
        } catch (Throwable) {
            // A broken log channel must not break a lookup.
        }
    }
}
