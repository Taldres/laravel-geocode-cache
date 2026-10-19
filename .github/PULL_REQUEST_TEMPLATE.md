<!--
Say what was wrong or missing, what changes for people using the package, and what a reviewer should look at twice.
Mention user-facing changes: they end up in the release notes. Put `Fixes #123` in the first paragraph if the pull
request closes an issue.
-->

## What and why

## Checklist

- [ ] The title reads `type: lowercase imperative`, such as `fix: keep an expired entry out of the refresh`. Mark breaking changes with `!`
- [ ] Tests cover the change, including the failure path and the boundaries
- [ ] `composer lint` and `composer test` pass
- [ ] Documentation, README and the Boost skill are updated if the public API, configuration or commands changed
