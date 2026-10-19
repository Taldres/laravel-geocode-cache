<?php

declare(strict_types=1);

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Taldres\GeocodeCache\Adapters\GoogleAdapter;
use Taldres\GeocodeCache\Contracts\SupportsPlaceIdLookup;
use Taldres\GeocodeCache\Data\Bounds;
use Taldres\GeocodeCache\Data\GeocodeQuery;
use Taldres\GeocodeCache\Data\PlaceIdQuery;
use Taldres\GeocodeCache\Exceptions\GeocodingFailedException;

function googleResponse(array $overrides = []): array
{
    return array_replace([
        'status' => 'OK',
        'results' => [[
            'formatted_address' => 'Alexanderplatz 1, 10178 Berlin, Germany',
            'address_components' => [['long_name' => 'Berlin']],
            'geometry' => ['location' => ['lat' => 52.5219184, 'lng' => 13.4132147], 'location_type' => 'ROOFTOP'],
            'place_id' => 'ChIJ-place',
        ], [
            'geometry' => ['location' => ['lat' => 1.0, 'lng' => 2.0]],
            'place_id' => 'second',
        ]],
    ], $overrides);
}

it('sends the query parameters to the Google Geocoding API', function () {
    Http::fake(['maps.googleapis.com/*' => Http::response(googleResponse())]);

    (new GoogleAdapter('secret-key'))->geocode(
        GeocodeQuery::make('Alexanderplatz 1', 'DE', 'de', 'de', new Bounds(52.3, 13.0, 52.7, 13.8)),
    );

    Http::assertSent(function (Request $request) {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        return $request->method() === 'GET'
            && str_starts_with($request->url(), 'https://maps.googleapis.com/maps/api/geocode/json?')
            && $query === [
                'address' => 'alexanderplatz 1',
                'components' => 'country:DE',
                'language' => 'de',
                'region' => 'de',
                'bounds' => '52.3000000,13.0000000|52.7000000,13.8000000',
                'key' => 'secret-key',
            ];
    });
});

it('leaves out the optional parameters that are not set', function () {
    Http::fake(['*' => Http::response(googleResponse())]);

    (new GoogleAdapter('secret-key'))->geocode(GeocodeQuery::make('01067'));

    Http::assertSent(function (Request $request) {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        return array_keys($query) === ['address', 'key'] && $query['address'] === '01067';
    });
});

it('keeps only the coordinates and the place id of the first result', function () {
    Http::fake(['*' => Http::response(googleResponse())]);

    $coordinates = (new GoogleAdapter('secret-key'))->geocode(GeocodeQuery::make('Alexanderplatz 1'));

    expect($coordinates->latitude)->toBe(52.5219184)
        ->and($coordinates->longitude)->toBe(13.4132147)
        ->and($coordinates->placeId)->toBe('ChIJ-place')
        ->and(get_object_vars($coordinates))->toHaveKeys(['latitude', 'longitude', 'placeId'])
        ->and(get_object_vars($coordinates))->toHaveCount(3);
});

it('returns null when Google found nothing', function () {
    Http::fake(['*' => Http::response(['status' => 'ZERO_RESULTS', 'results' => []])]);

    expect((new GoogleAdapter('secret-key'))->geocode(GeocodeQuery::make('qwertzuiop')))->toBeNull();
});

it('fails with the status Google reported and none of its text', function (string $status) {
    Http::fake(['*' => Http::response(['status' => $status, 'error_message' => 'Something is wrong.', 'results' => []])]);

    try {
        (new GoogleAdapter('secret-key'))->geocode(GeocodeQuery::make('Berlin'));
        $this->fail('The lookup should have failed.');
    } catch (GeocodingFailedException $exception) {
        expect($exception->getMessage())->toContain("[{$status}]")->not->toContain('Something is wrong.')
            ->and($exception->reason)->toBe("status_{$status}");
    }
})->with(['REQUEST_DENIED', 'OVER_QUERY_LIMIT', 'INVALID_REQUEST', 'UNKNOWN_ERROR']);

it('fails on an HTTP error', function () {
    Http::fake(['*' => Http::response('Server Error', 503)]);

    expect(fn () => (new GoogleAdapter('secret-key'))->geocode(GeocodeQuery::make('Berlin')))
        ->toThrow(GeocodingFailedException::class, 'HTTP status 503');
});

it('fails on a response that is not JSON', function () {
    Http::fake(['*' => Http::response('<html></html>')]);

    expect(fn () => (new GoogleAdapter('secret-key'))->geocode(GeocodeQuery::make('Berlin')))
        ->toThrow(GeocodingFailedException::class, '[UNKNOWN]');
});

it('fails on an OK response without coordinates', function () {
    Http::fake(['*' => Http::response(['status' => 'OK', 'results' => [['place_id' => 'x']]])]);

    expect(fn () => (new GoogleAdapter('secret-key'))->geocode(GeocodeQuery::make('Berlin')))
        ->toThrow(GeocodingFailedException::class, 'without usable coordinates');
});

it('does not leak the API key or the address when the connection fails', function () {
    Http::fake(['*' => fn () => throw new ConnectionException('cURL error 28 for https://maps.googleapis.com/maps/api/geocode/json?address=secret-street&key=secret-key')]);

    try {
        (new GoogleAdapter('secret-key'))->geocode(GeocodeQuery::make('secret-street'));
        $this->fail('The lookup should have failed.');
    } catch (GeocodingFailedException $exception) {
        expect($exception->getMessage())->not->toContain('secret')
            ->and($exception->getPrevious())->toBeNull();
    }
});

it('refuses an empty API key', function () {
    new GoogleAdapter('');
})->throws(InvalidArgumentException::class, 'API key');

it('reads from Google', function () {
    expect((new GoogleAdapter('secret-key'))->source())->toBe('google')
        ->and((new GoogleAdapter('secret-key'))->maximumPrecision())->toBe(7)
        ->and((new GoogleAdapter('secret-key'))->maximumRetentionDays())->toBe(29);
});

it('can look place IDs up', function () {
    expect(new GoogleAdapter('secret-key'))->toBeInstanceOf(SupportsPlaceIdLookup::class);
});

it('sends only the place ID and the key when it looks a place ID up', function () {
    Http::fake(['maps.googleapis.com/*' => Http::response(googleResponse())]);

    $coordinates = (new GoogleAdapter('secret-key'))->geocodePlaceId(PlaceIdQuery::make('ChIJ-AbC_1'));

    Http::assertSent(function (Request $request) {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        return $request->method() === 'GET' && $query === ['place_id' => 'ChIJ-AbC_1', 'key' => 'secret-key'];
    });
    expect($coordinates->latitude)->toBe(52.5219184)
        ->and($coordinates->longitude)->toBe(13.4132147)
        ->and($coordinates->placeId)->toBe('ChIJ-place');
});

it('finds nothing for a place ID that is no longer valid', function (string $status) {
    Http::fake(['*' => Http::response(['status' => $status, 'results' => []])]);

    expect((new GoogleAdapter('secret-key'))->geocodePlaceId(PlaceIdQuery::make('ChIJ-gone')))->toBeNull();
})->with(['ZERO_RESULTS']);

it('fails on a place ID request that Google rejects', function (string $status) {
    Http::fake(['*' => Http::response(['status' => $status, 'error_message' => 'Secret text.', 'results' => []])]);

    try {
        (new GoogleAdapter('secret-key'))->geocodePlaceId(PlaceIdQuery::make('ChIJ-bad'));
        $this->fail('The lookup should have failed.');
    } catch (GeocodingFailedException $exception) {
        expect($exception->reason)->toBe("status_{$status}")->and($exception->getMessage())->not->toContain('Secret text.');
    }
})->with(['INVALID_REQUEST', 'REQUEST_DENIED', 'OVER_QUERY_LIMIT', 'NOT_FOUND']);

it('reports the status of the body when Google answers a bad request with HTTP 400', function () {
    Http::fake(['*' => Http::response(['status' => 'INVALID_REQUEST', 'error_message' => "Invalid 'place_id' parameter.", 'results' => []], 400)]);

    try {
        (new GoogleAdapter('secret-key'))->geocodePlaceId(PlaceIdQuery::make('ChIJ-bad'));
        $this->fail('The lookup should have failed.');
    } catch (GeocodingFailedException $exception) {
        expect($exception->reason)->toBe('status_INVALID_REQUEST')
            ->and($exception->getMessage())->not->toContain('place_id');
    }
});

it('falls back to the HTTP status when the body of a client error has no status', function () {
    Http::fake(['*' => Http::response('Bad Request', 400)]);

    try {
        (new GoogleAdapter('secret-key'))->geocode(GeocodeQuery::make('Berlin'));
        $this->fail('The lookup should have failed.');
    } catch (GeocodingFailedException $exception) {
        expect($exception->reason)->toBe('http_400');
    }
});

it('keeps the HTTP status for a server error even if the body has a status', function () {
    Http::fake(['*' => Http::response(['status' => 'UNKNOWN_ERROR'], 503)]);

    try {
        (new GoogleAdapter('secret-key'))->geocode(GeocodeQuery::make('Berlin'));
        $this->fail('The lookup should have failed.');
    } catch (GeocodingFailedException $exception) {
        expect($exception->reason)->toBe('http_503');
    }
});

it('does not leak the API key or the place ID when the request fails', function () {
    Http::fake(['*' => fn () => throw new ConnectionException('cURL error: https://x/?place_id=ChIJ-secret&key=secret-key')]);

    try {
        (new GoogleAdapter('secret-key'))->geocodePlaceId(PlaceIdQuery::make('ChIJ-secret'));
        $this->fail('The lookup should have failed.');
    } catch (GeocodingFailedException $exception) {
        expect($exception->getMessage().$exception->reason)->not->toContain('secret');
    }
});

it('puts only a valid status code of Google into the exception', function (int $http) {
    Http::fake(['*' => Http::response(['status' => 'Please <b>do</b> this and that', 'results' => []], $http)]);

    try {
        (new GoogleAdapter('secret-key'))->geocodePlaceId(PlaceIdQuery::make('ChIJ-bad'));
        $this->fail('The lookup should have failed.');
    } catch (GeocodingFailedException $exception) {
        expect($exception->getMessage())->not->toContain('Please')
            ->and($exception->reason)->toBe('status_unknown');
    }
})->with([200, 400]);
