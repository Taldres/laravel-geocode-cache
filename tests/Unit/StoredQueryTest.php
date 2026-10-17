<?php

declare(strict_types=1);

use Taldres\GeocodeCache\Data\GeocodeQuery;
use Taldres\GeocodeCache\Data\PlaceIdQuery;
use Taldres\GeocodeCache\Support\StoredQuery;

it('reads a payload without a type as an address query, like the ones stored before place IDs', function () {
    $payload = GeocodeQuery::make('Berlin', 'DE')->toArray();

    expect($payload)->not->toHaveKey('type')
        ->and(StoredQuery::fromArray($payload))->toBeInstanceOf(GeocodeQuery::class)
        ->and(StoredQuery::fromArray($payload)->hash('google'))->toBe(GeocodeQuery::make('Berlin', 'DE')->hash('google'));
});

it('reads a place ID payload', function () {
    $query = StoredQuery::fromArray(PlaceIdQuery::make('ChIJ-abc')->toArray());

    expect($query)->toBeInstanceOf(PlaceIdQuery::class)
        ->and($query->hash('google'))->toBe(PlaceIdQuery::make('ChIJ-abc')->hash('google'));
});

it('rejects a payload of an unknown type', function (mixed $type) {
    expect(fn () => StoredQuery::fromArray(['type' => $type, 'search' => 'berlin']))->toThrow(InvalidArgumentException::class);
})->with(['unknown' => 'reverse', 'not a string' => 5, 'empty' => '']);
