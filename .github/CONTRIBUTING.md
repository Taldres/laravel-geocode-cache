# Contributing

Contributions are welcome: bug reports, fixes and documentation improvements alike. For significant changes, please open
an issue first so we can discuss the approach.

## Setup

```bash
git clone https://github.com/Taldres/laravel-geocode-cache.git
cd laravel-geocode-cache
composer install
```

## Before Opening a Pull Request

Fix the code style, then run all checks. The CI runs the same checks and does not fix anything for you:

```bash
composer lint
composer test
```

`composer test` only checks and never changes files. Its steps can also be run on their own:

```bash
composer analyse     # static analysis with PHPStan
composer lint:check  # code style check with Pint
composer test:types  # type coverage
composer test:unit   # the Pest test suite
```

The test suite runs on an in-memory SQLite database. To run it against MySQL or PostgreSQL, point the `DB_*` variables
at an empty database. The tests drop all of its tables:

```bash
DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT=5432 DB_DATABASE=testing DB_USERNAME=testing DB_PASSWORD=password composer test:unit
```

- Add or update tests for every change in behavior.
- Keep a pull request focused on one change.
- Describe user-facing changes in the pull request description. The release notes are generated from pull request
  titles, grouped by the label a maintainer assigns: `breaking`, `enhancement`, `bug`, `documentation`,
  `dependencies` or `maintenance`. Pull requests labelled `skip-changelog` are left out.

## Security Vulnerabilities

Please do not open a public issue. See [SECURITY](SECURITY.md) instead.
