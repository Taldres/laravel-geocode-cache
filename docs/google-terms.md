# Google Terms

The defaults of the package follow the Google Maps Platform terms as reviewed on **17 October 2026**. Terms change.
Check the original sources before you rely on this page. The contract that applies to you and your billing address
decide.

## Retention of Coordinates

Google allows the temporary storage of latitude and longitude values from the Geocoding API for up to **30 consecutive
calendar days**. After that the stored values have to be deleted.

- For customers with a billing address in the European Economic Area, the rule is in section 6.2.1 of the EEA terms.
- For customers outside the EEA, the corresponding rule is in section 6.3.1.

The package defaults to **29 days** and rejects a `ttl_days` or `source_ttl_days['google']` above 30 for results from Google, and it does not return an entry that was stored for a longer period. The remaining day gives the
scheduled pruning room. It does not replace a scheduler that runs reliably.

## Not the Whole Response

The permission for coordinates is not a permission to store every field of a response. The package stores only the
coordinates and optionally the `place_id`. Formatted addresses, address components and other fields are not stored.

Google lets you store a `place_id` indefinitely. The package still removes it together with its entry.

## The Exception for Persistent Storage

The EEA terms (section 6.2.2) contain a limited exception that allows persistent storage of coordinates and some address
data for certain features directly related to an end user. The data has to stay logically assigned to that user, may not
be used across users, and may not replace additional service calls.

**This package is not based on that exception.** A cache that is shared between users and holds addresses or postal
codes for everyone does not fit it. The package stores for a limited time only.

## Attribution and Maps

The retention is only one part of the terms. Attribution, display and permitted use have their own rules, and the rules
for maps differ between EEA and non-EEA contracts. The package makes no general statement that results may be shown on
any map.

## Sources

The retention clauses above were checked against the pages on 17 October 2026. The EEA terms were last modified on
10 June 2026 and the terms outside the EEA on 15 October 2026.

- [Google Maps Platform terms for the EEA](https://cloud.google.com/terms/maps-platform/eea/maps-service-terms)
- [Google Maps Platform terms outside the EEA](https://cloud.google.com/maps-platform/terms/maps-service-terms)
- [Geocoding API policies](https://developers.google.com/maps/documentation/geocoding/policies)
