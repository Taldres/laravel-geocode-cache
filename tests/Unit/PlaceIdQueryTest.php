<?php

declare(strict_types=1);

use Taldres\GeocodeCache\Data\GeocodeQuery;
use Taldres\GeocodeCache\Data\PlaceIdQuery;

it('keeps the place ID exactly as it is, apart from surrounding whitespace', function () {
    expect(PlaceIdQuery::make("  ChIJ-AbC_123 \n")->placeId)->toBe('ChIJ-AbC_123');
});

it('rejects a place ID that cannot be one', function (string $placeId) {
    expect(fn () => PlaceIdQuery::make($placeId))->toThrow(InvalidArgumentException::class);
})->with([
    'empty' => '',
    'blank' => '   ',
    'with a space' => 'ChIJ abc',
    'with a line break' => "ChIJ\nabc",
    'too long' => str_repeat('a', 513),
]);

it('accepts the longest place ID the table stores', function () {
    expect(PlaceIdQuery::make(str_repeat('a', 512))->placeId)->toHaveLength(512);
});

it('hashes the kind of the query, the source and the unchanged place ID', function () {
    $query = PlaceIdQuery::make('ChIJ-abc');

    expect($query->hash('google'))->toMatch('/^[a-f0-9]{64}$/')
        ->and($query->hash('google'))->toBe(PlaceIdQuery::make('ChIJ-abc')->hash('google'))
        ->and($query->hash('google'))->not->toBe($query->hash('mapbox'))
        ->and($query->hash('google'))->not->toBe(PlaceIdQuery::make('ChIJ-ABC')->hash('google'))
        ->and($query->hash('google'))->not->toBe(GeocodeQuery::make('ChIJ-abc')->hash('google'));
});

it('rebuilds a query from its array', function () {
    $query = PlaceIdQuery::make('ChIJ-abc');

    expect($query->toArray())->toBe(['type' => 'place_id', 'place_id' => 'ChIJ-abc'])
        ->and(PlaceIdQuery::fromArray($query->toArray())->hash('google'))->toBe($query->hash('google'));
});

it('rejects a payload that is not a place ID query', function (array $payload) {
    expect(fn () => PlaceIdQuery::fromArray($payload))->toThrow(InvalidArgumentException::class);
})->with([
    'empty' => [[]],
    'no place ID' => [['type' => 'place_id']],
    'not a string' => [['type' => 'place_id', 'place_id' => 5]],
    'blank' => [['type' => 'place_id', 'place_id' => ' ']],
]);
