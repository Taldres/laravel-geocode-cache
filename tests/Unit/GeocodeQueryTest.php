<?php

declare(strict_types=1);

use Taldres\GeocodeCache\Data\Bounds;
use Taldres\GeocodeCache\Data\GeocodeQuery;

it('normalises the search text, the country, the language and the region', function () {
    $query = GeocodeQuery::make("  Alexanderplatz \n 1,   BERLIN ", ' de ', 'DE-at', 'DE');

    expect($query->search)->toBe('alexanderplatz 1, berlin')
        ->and($query->country)->toBe('DE')
        ->and($query->language)->toBe('de-at')
        ->and($query->region)->toBe('de');
});

it('treats blank optional parameters as missing', function () {
    $query = GeocodeQuery::make('Berlin', '', '  ', '');

    expect($query->country)->toBeNull()
        ->and($query->language)->toBeNull()
        ->and($query->region)->toBeNull();
});

it('keeps the leading zeros of a postal code', function () {
    expect(GeocodeQuery::make('01067', 'DE')->search)->toBe('01067')
        ->and(GeocodeQuery::make('01067', 'DE')->hash('google'))
        ->not->toBe(GeocodeQuery::make('1067', 'DE')->hash('google'));
});

it('gives equivalent queries the same hash', function () {
    $hash = GeocodeQuery::make('Alexanderplatz 1, Berlin', 'DE', 'de')->hash('google');

    expect(GeocodeQuery::make("  ALEXANDERPLATZ   1, berlin\n", 'de', 'DE')->hash('google'))->toBe($hash)
        ->and($hash)->toMatch('/^[0-9a-f]{64}$/');
});

it('gives queries that differ in any parameter a different hash', function (GeocodeQuery $other) {
    $base = GeocodeQuery::make('Springfield', 'US', 'en', 'us', new Bounds(37.0, -90.0, 40.0, -85.0));

    expect($other->hash('google'))->not->toBe($base->hash('google'));
})->with([
    'search' => fn () => GeocodeQuery::make('Shelbyville', 'US', 'en', 'us', new Bounds(37.0, -90.0, 40.0, -85.0)),
    'country' => fn () => GeocodeQuery::make('Springfield', 'CA', 'en', 'us', new Bounds(37.0, -90.0, 40.0, -85.0)),
    'no country' => fn () => GeocodeQuery::make('Springfield', null, 'en', 'us', new Bounds(37.0, -90.0, 40.0, -85.0)),
    'language' => fn () => GeocodeQuery::make('Springfield', 'US', 'de', 'us', new Bounds(37.0, -90.0, 40.0, -85.0)),
    'region' => fn () => GeocodeQuery::make('Springfield', 'US', 'en', 'ca', new Bounds(37.0, -90.0, 40.0, -85.0)),
    'bounds' => fn () => GeocodeQuery::make('Springfield', 'US', 'en', 'us', new Bounds(37.0, -90.0, 40.0, -84.0)),
    'no bounds' => fn () => GeocodeQuery::make('Springfield', 'US', 'en', 'us'),
]);

it('gives the same query a different hash for another data source', function () {
    $query = GeocodeQuery::make('Berlin', 'DE');

    expect($query->hash('google'))->not->toBe($query->hash('other'));
});

it('rebuilds a query from its array', function () {
    $query = GeocodeQuery::make('Berlin', 'DE', 'de', 'de', new Bounds(52.3, 13.0, 52.7, 13.8));

    expect(GeocodeQuery::fromArray($query->toArray())->hash('google'))->toBe($query->hash('google'));
});

it('rejects a payload that is not a query', function (array $payload) {
    GeocodeQuery::fromArray($payload);
})->throws(InvalidArgumentException::class)->with([
    'empty' => [[]],
    'blank search' => [['search' => '  ']],
    'invalid country' => [['search' => 'Berlin', 'country' => 'Germany']],
]);

it('rejects invalid parameters', function (Closure $make) {
    $make();
})->throws(InvalidArgumentException::class)->with([
    'empty search' => [fn () => GeocodeQuery::make('  ')],
    'country with three letters' => [fn () => GeocodeQuery::make('Berlin', 'DEU')],
    'numeric country' => [fn () => GeocodeQuery::make('Berlin', '49')],
    'language with spaces' => [fn () => GeocodeQuery::make('Berlin', null, 'de de')],
    'region with digits' => [fn () => GeocodeQuery::make('Berlin', null, null, 'd3')],
]);
