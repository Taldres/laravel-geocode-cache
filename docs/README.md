# Laravel Geocode Cache Documentation

- [Introduction](introduction.md): what the package does and what it does not do
- [Installation](installation.md): requirements, installation and the scheduler entry you have to add
- [Configuration](configuration.md): every setting and its environment variable

## Usage

- [Looking Coordinates Up](usage.md): queries, results, postal codes and drivers
- [Coordinates for Your Models](models.md): keeping the address or place ID on a model and asking the cache for the rest
- [Failures, Empty Results and Locks](failures-and-locks.md): how errors, missing results and parallel requests behave
- [Request Statistics](statistics.md): counting how often and how regularly each query hash is requested
- [Events](events.md): the two events for a call of the service and a failed lookup
- [Logging](logging.md): the optional operational log and what it never contains
- [Refreshing Entries](refreshing.md): renewing entries that are still in use shortly before they expire
- [Custom Adapters](custom-adapters.md): adding another geocoding service
- [Testing Your Application](testing.md): faking the service and the clock

## Retention

- [Retention and Deletion](retention-and-deletion.md): what is stored, how long, how it is deleted and which copies are
  yours to handle
- [Google Terms](google-terms.md): the terms the defaults are based on, with date and sources

## More

- [Versioning and Stable API](stability.md): what stays stable within a major version and what is internal
- [AI Agents](ai-agents.md): the Laravel Boost skill that ships with the package
