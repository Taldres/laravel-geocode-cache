<?php

declare(strict_types=1);

use Taldres\GeocodeCache\Data\Bounds;
use Taldres\GeocodeCache\Data\GeocodeQuery;
use Taldres\GeocodeCache\Data\PlaceIdQuery;

// Entries are found again by their hash, so a change of these values would orphan every stored entry.
it('keeps the hashes of the queries stable', function () {
    expect(GeocodeQuery::make('Alexanderplatz 1, Berlin')->hash('google'))
        ->toBe('136f96f3517de1eb5356487b7238f7361011c7e06412cd1450e0590cd287149c')
        ->and(GeocodeQuery::make('Springfield', 'US', 'en', 'us', new Bounds(37.0, -90.0, 40.0, -85.0))->hash('google'))
        ->toBe('a0dbfc179ec94636433cd5b9766ea533cf2f01eedd8d71b270fe5e88e0dc697d')
        ->and(PlaceIdQuery::make('ChIJ-AbC_123')->hash('google'))
        ->toBe('6cccbae43d7ef0498ca45c9fac4ae5f98a5528b949cf41072e47852cf55067f0');
});
