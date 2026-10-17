<?php

declare(strict_types=1);

use Taldres\GeocodeCache\Data\Bounds;
use Taldres\GeocodeCache\Data\Coordinates;
use Taldres\GeocodeCache\Exceptions\GeocodingFailedException;

it('builds a canonical key from the corners of the bounds', function () {
    expect((new Bounds(52.3, 13.0, 52.7, 13.8))->toKey())->toBe('52.3000000,13.0000000|52.7000000,13.8000000');
});

it('rejects invalid bounds', function (float ...$corners) {
    new Bounds(...$corners);
})->throws(InvalidArgumentException::class)->with([
    'latitude too high' => [91.0, 0.0, 92.0, 1.0],
    'longitude too low' => [0.0, -181.0, 1.0, 1.0],
    'corners swapped' => [10.0, 0.0, 5.0, 1.0],
]);

it('rounds coordinates to the seven decimals the table stores', function () {
    $coordinates = new Coordinates(52.123456789, 13.987654321, 'place');

    expect($coordinates->latitude)->toBe(52.1234568)
        ->and($coordinates->longitude)->toBe(13.9876543)
        ->and($coordinates->placeId)->toBe('place');
});

it('rejects coordinates out of range', function () {
    new Coordinates(90.1, 0.0);
})->throws(InvalidArgumentException::class);

it('builds coordinates from a response', function () {
    $coordinates = Coordinates::fromResponse('52.5', 13, 'abc');

    expect($coordinates->latitude)->toBe(52.5)
        ->and($coordinates->longitude)->toBe(13.0)
        ->and($coordinates->placeId)->toBe('abc')
        ->and(Coordinates::fromResponse(1, 2, '')->placeId)->toBeNull()
        ->and(Coordinates::fromResponse(1, 2, 12)->placeId)->toBeNull();
});

it('rejects a response without usable coordinates', function (mixed $latitude, mixed $longitude) {
    Coordinates::fromResponse($latitude, $longitude);
})->throws(GeocodingFailedException::class, 'without usable coordinates')->with([
    'missing latitude' => [null, 13.0],
    'text' => ['north', 13.0],
    'out of range' => [123.0, 13.0],
]);
