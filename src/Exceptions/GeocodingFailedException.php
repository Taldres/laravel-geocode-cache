<?php

declare(strict_types=1);

namespace Taldres\GeocodeCache\Exceptions;

use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class GeocodingFailedException extends RuntimeException
{
    /**
     * @param  string  $reason  A fixed code such as "http_503", safe to log, unlike the message.
     */
    public function __construct(string $message = '', public readonly string $reason = 'unspecified', ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }

    public static function connection(): self
    {
        return new self('The geocoding service could not be reached.', 'connection');
    }

    public static function httpStatus(int $status): self
    {
        return new self("The geocoding service answered with HTTP status {$status}.", "http_{$status}");
    }

    public static function serviceStatus(string $status): self
    {
        // Only a plain status code gets into the message and the reason, never other text of the service.
        $valid = Str::isMatch('/^[A-Z_]{1,40}\z/', $status);

        return new self(
            'The geocoding service reported the status ['.($valid ? $status : 'UNKNOWN').'].',
            $valid ? 'status_'.$status : 'status_unknown',
        );
    }

    public static function malformedResponse(): self
    {
        return new self('The geocoding service returned a response without usable coordinates.', 'malformed_response');
    }

    public static function lockTimeout(): self
    {
        return new self('Another process is still looking up the same query. Try again in a moment.', 'lock_timeout');
    }
}
