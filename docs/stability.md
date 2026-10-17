# Versioning and Stable API

The package follows [Semantic Versioning](https://semver.org). Within a major version, the following parts do not change
in a way that breaks code written against them. A breaking change needs a new major version.

## Stable

- **The facade and the geocoder:** `GeocodeCache::geocode()`, `refresh()`, `driver()`, `extend()`, `supports()`,
  `supportsPlaceIds()`, `adapter()`, `retentionDays()` and `precision()`, with their parameters and return types.
- **The data objects:** `GeocodeQuery`, `PlaceIdQuery`, `Bounds`, `Coordinates` and `GeocodeResult` with their public
  properties and factories. The cache key of an existing query stays the same, so a new minor version does not
  invalidate stored entries.
- **The adapter contracts:** `Contracts\Adapter` and `Contracts\SupportsPlaceIdLookup`. A method is only added in a
  major version, because every custom adapter would have to implement it.
- **The configuration:** every key of `config/geocode-cache.php`, its meaning and its environment variable. A new key
  gets a default that keeps the old behaviour.
- **The tables:** the columns of `geocode_cache` and `geocode_cache_statistics`. A change comes as a new migration.
  The models read them. Writing to the tables yourself is not supported.
- **The commands:** `geocode-cache:prune`, `geocode-cache:refresh` and `geocode-cache:statistics` with their options
  and exit codes, and the publish tags `geocode-cache`, `geocode-cache-config` and `geocode-cache-migrations`.
- **The exceptions:** their classes and parents. `GeocodingLockTimeoutException` stays a `GeocodingFailedException`,
  `InvalidConfigurationException` an `InvalidArgumentException` and `UnsupportedQueryException` a `LogicException`.
- **The events:** `GeocodeServiceCalled` and `GeocodeLookupFailed` with their properties. A minor version may add a
  property, never remove or retype one.
- **The rules of the package:** the retention and deletion rules in [Retention and Deletion](retention-and-deletion.md)
  do not get weaker in a minor version.

## May Grow in a Minor Version

- **The `reason` codes** of `GeocodingFailedException`, the log and `GeocodeLookupFailed`: `connection`,
  `http_<status>`, `status_<CODE>`, `malformed_response`, `lock_timeout`, `unspecified` and `unexpected` exist today.
  Match the ones you know and treat any other value as a failure of the service.
- **The text of messages** of exceptions and of the log. Never parse them.
- **The statuses of the service** that a driver maps to a failure.

## Internal

Not covered, even where PHP lets you call them: methods marked `@internal` (`refreshUsing()`, `shareWithPlace()`), the
classes in `Taldres\GeocodeCache\Support`, the private and protected members, and the order in which events of
different processes arrive.
