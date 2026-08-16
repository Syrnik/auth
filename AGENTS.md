# AGENTS.md

## Architecture Decisions

Application-level decisions that constrain the code live in `docs/adr/`. Read the
relevant record before changing the area it covers — these are the reasons behind
non-obvious constraints, and they are not re-derivable from the code alone.

- `001-profile-config-boundaries.md` — which config governs which block of the
  user profile (`my/`): site's `personal_fields` vs auth's `login_methods` vs the
  contact's actual linked accounts.

## Tests

Tests live flat in `/tests` (no subdirectories, one class per file — see the existing files
for the pattern), and never ship: `lib/config/exclude.php` keeps the whole directory,
`phpunit.xml` and the run cache out of the release bundle.

`tests/init.php` is the bootstrap — `tests/` is outside the app's autoload scan (which only
covers `lib/`), so any shared test helper needs an explicit `require_once` there, same as the
two existing traits (`authTestTemporaryTablesTrait`, `authTestConfigOverrideTrait`).

Run from the app root. The `2>/dev/null` isn't optional — without it, any PHP-8.4-vs-phar
deprecation notice the framework happens to emit gets an xdebug stack trace dumped to stderr
on top of PHPUnit's own output, burying the pass/fail summary under noise:

```
/c/osp6/modules/PHP-8.4/php.exe /c/osp6/modules/PHP-8.2/phpunit 2>/dev/null
/c/osp6/modules/PHP-8.4/php.exe /c/osp6/modules/PHP-8.2/phpunit --filter <TestClass> 2>/dev/null
```

Domain-dependent tests assume `syrnik.local` is a routed domain; set `AUTH_TEST_HOST` before
running if your checkout uses a different one.

Tests run against the real dev database (`wa-config/db.php`). Anything that touches a table
this app owns (`auth_signup_confirm`, `auth_profile_confirm`, `auth_password_recovery`) should
shadow it with `authTestTemporaryTablesTrait::setUpTemporaryTables()` — a real
`CREATE TEMPORARY TABLE` that shares the table's name and is invisible to any other
connection, rather than inserting rows and deleting them in `tearDown()`, which leaves rows
behind if the test fails partway through. Tests that depend on domain settings
(`login_methods` and friends) should use `authTestConfigOverrideTrait::overrideAuthConfig()`
instead of relying on whatever is actually configured for `syrnik.local` right now.

## Commit Messages

All commits must follow the [Conventional Commits](https://www.conventionalcommits.org/en/v1.0.0/) specification.

Format: `<type>[optional scope]: <description>`

Common types: `feat`, `fix`, `chore`, `refactor`, `docs`, `test`, `ci`.

When the work relates to a task from the wa-tasks tracker, append the task
number to the end of the subject line as `Task: #<number>`, e.g.
`fix: validate redirect targets Task: #76.26`.

## Changelog

The `CHANGELOG.md` file must follow the [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) format.

- Add entries under `[Unreleased]` during development.
- On release, rename `[Unreleased]` to the version number with the release date.
- Sections within a version: `Added`, `Changed`, `Deprecated`, `Removed`, `Fixed`, `Security`.
