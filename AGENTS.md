# AGENTS.md

Rules for AI coding agents working in this repository. The repository is a Laravel package. Keep it focused, idiomatic
and easy for Laravel developers to install, test and maintain.

## Language and Style

- Everything is English: code, comments, tests, documentation, commit messages.
- Use Laravel's facades and helpers for what they offer. Use `Str::`, `Arr::` and `Collection` instead of the plain `str_*`
  and `array_*` functions where they fit, `Date::now()` for the current time and `config()->string()` and friends for
  typed configuration.
- Never call `env()` outside of `config/geocode-cache.php`.
- Use Laravel-native package APIs (`mergeConfigFrom`, `publishes`, `publishesMigrations`, `Manager`, atomic locks)
  before adding abstractions.

## Laravel Boost

This package is developed with [Laravel Boost](https://laravel.com/docs/boost).

- Before changes that depend on Laravel behavior, search the documentation with the Boost `search-docs` tool. The MCP
  server is configured in `.mcp.json` and runs through Testbench in the local environment:
  `APP_ENV=local APP_DEBUG=true vendor/bin/testbench boost:mcp`. Boost only runs when the environment is `local`.
- The Boost resources of the package live in `resources/boost`: `guidelines/core.blade.php` and
  `skills/laravel-geocode-cache-development/SKILL.md`. When the public API, the configuration, the commands or the
  setup change, update the skill and the documentation in `docs/` in the same change. The README repeats the
  installation and a quick start, so update it too when those change.

## Checks

- Run `composer lint` to fix the code style, then `composer test` before every commit. `composer test` runs PHPStan, the
  code style check, type coverage and the Pest suite without changing files.
- Every commit must pass `composer test` on its own.
- Write the failing test first. Cover the happy path, the failure path and the boundaries, especially the moment an entry
  expires, failed lookups, empty results and the cache key.

## Domain Rules

These are the point of the package. Do not weaken them without an explicit decision.

- An expired entry is never returned, also not when the service fails. It is deleted when it is accessed.
- Reading an entry never extends `expires_at`. Only a new, successful lookup stores a new period.
- A failed or empty lookup never changes a stored entry.
- Only the coordinates and the optional place ID of a response are stored, never the formatted address or other fields. The query is stored encrypted only with `refresh.store_query`.
- The default retention is 29 days. An adapter reports the maximum for its source and the cache never exceeds it. For results from Google that is 30 days. The retention belongs to the source (`source_ttl_days`), not to a driver.
- The package log (`Support\GeocodeLog`) and exception messages never contain the searched text, coordinates, place IDs or hashes.
- The statistics hold counters per hash and day only, never a query, coordinates or a place ID.
- Exception messages never contain the request URL, because it holds the API key and the searched address.
- The cache key covers every parameter that influences the result.

## Local Skills

The skills in `.agents/skills` come from [laravel/package-skeleton](https://github.com/laravel/package-skeleton).
`.claude` links to `.agents`, and `CLAUDE.md` links to this file.

- `package-scaffold`: wiring package capabilities through the service provider.
- `package-testing`: tests with Pest and Orchestra Testbench.
- `package-release`: changelog and releases. Never tag, push or publish without approval.
- `package-compatibility`: the PHP and Laravel support matrix (PHP 8.3 to 8.5, Laravel 12 and 13).
- `package-generate-skill`: updating the bundled Boost skill from the implementation.

## Branches

Branch names follow the [Conventional Branch](https://conventional-branch.github.io/) format with the Conventional
Commits types as prefixes: `<type>/<short-description>`, for example `feat/mapbox-adapter` or `docs/scheduler-setup`.
`type` is one of `feat`, `fix`, `docs`, `style`, `refactor`, `perf`, `test`, `build`, `ci`, `chore` or `revert`. Never
commit directly to `main`.

## Commits

Commit messages follow [Conventional Commits 1.0.0](https://www.conventionalcommits.org/en/v1.0.0/):
`<type>[optional scope]: <description>`, imperative mood, lowercase, no trailing period, header under 72 characters.
Keep each commit to one logical change. Mark breaking changes with `!` and a `BREAKING CHANGE:` footer.

## Attribution

- Do not add `Co-authored-by:` trailers or any other AI attribution to commits or pull request descriptions.
- Commits are authored by the configured git user only.
