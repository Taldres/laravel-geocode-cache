# Laravel Geocode Cache Documentation

## Getting Started

- [Introduction](introduction.md): what the package does and what it does not do
- [Installation](installation.md): requirements, setup and the scheduler entry you have to add
- [Configuration](configuration.md): every setting and its environment variable

## Usage

- [Looking Coordinates Up](usage.md): queries, results, place IDs and drivers
- [Coordinates for Your Models](models.md): keeping the address or place ID on a model and asking the cache for the rest
- [Failures, Empty Results and Locks](failures-and-locks.md): exceptions, reason codes and parallel requests
- [Refreshing Entries](refreshing.md): renewing entries that are still in use shortly before they expire
- [Testing Your Application](testing.md): faking the service and the clock

## Operations

- [Retention and Deletion](retention-and-deletion.md): what is stored, how long, how it is deleted and which copies are yours to handle
- [Google Terms](google-terms.md): the terms the defaults are based on, with date and sources
- [Request Statistics](statistics.md): how often and how regularly each query hash is requested
- [Events and Logging](events-and-logging.md): the two events and the optional log

## Extending

- [Custom Adapters](custom-adapters.md): adding another geocoding service
