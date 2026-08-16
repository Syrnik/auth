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

## PHP Compatibility

Target range is PHP 8.2–8.5, declared in `lib/config/requirements.php` (installer-enforced)
and in both READMEs. `.github/workflows/php-version-check.yml` lints every `*.php` file
(`php -l`) on a matrix of 8.2/8.3/8.4/8.5 on every push and PR — the fast, reliable check;
run it locally with any installed PHP version before pushing if you touched syntax.

`psalm82.xml` and `psalm85.xml` pin the two ends of the range (`phpVersion="8.2"` /
`"8.5"`) for deeper static analysis — deprecations or removed features that only show up
at one boundary. Style and structure copied from the `sdekint` plugin's own
`psalm74.xml`/`psalm85.xml`. Run from the app root:

```
/c/osp6/modules/PHP-8.4/php.exe -n -c tests/psalm.ini /c/osp6/modules/PHP-8.4/psalm.phar --config=psalm82.xml --no-cache
/c/osp6/modules/PHP-8.4/php.exe -n -c tests/psalm.ini /c/osp6/modules/PHP-8.4/psalm.phar --config=psalm85.xml --no-cache
```

`tests/psalm.ini` exists only to work around this machine's PHPRC always pointing at
PHP-8.4's own `php.ini` (which fails to load openssl for a bare CLI invocation) — see the
comment in that file. Without it, psalm.phar's bundled Box requirements checker hard-fails
before analysis even starts.

Both configs scan `wa-system`/`wa-config`/`wa-plugins` for symbol resolution (`waContact`,
`waModel`, `wa()`...) but wrap them in a nested `<ignoreFiles>` so nothing gets reported
from there — a plain `<extraFiles>` block (what `sdekint`'s own config uses) gets fully
analyzed by this Psalm version too, and the framework was never written against Psalm, so
that buries the app's own findings under hundreds nobody here can act on. `wa-apps/` is
deliberately left out of the scan (not just out of the report): `wa-apps/auth/lib` sits
inside it, and ignoring the whole tree ignores this app's own code too — confirmed by
watching the run collapse to "No files analyzed" with it included.

`errorLevel="4"` matches `sdekint`'s own config. On this app's ~90 files (with the framework
noise removed as above) the counts by level were 1→939, 2→441, 3→269, **4→247**, 5→227,
6→222, 7→211, 8→211: most of the drop happens going from 1 to 4 (pedantic checks like
requiring `#[Override]` everywhere), while 4 down to the loosest level 8 only trims another
36. Level 4 is a reasonable place to sit rather than a compromise — going any looser barely
reduces the count. It also means nothing here has been triaged yet: a first run at any level
returns real work, not noise, so read it as a backlog rather than a gate.

`psalm82-baseline.xml`/`psalm85-baseline.xml` freeze that initial backlog (247 findings each,
regenerated together — the two configs differ only in `phpVersion`, so their baselines cover
the same findings) — a plain run now reports clean, and only a genuinely new issue fails it.
The `errorBaseline` attribute in each config points at its own file; both are read
automatically, no extra flag needed:

```
/c/osp6/modules/PHP-8.4/php.exe -n -c tests/psalm.ini /c/osp6/modules/PHP-8.4/psalm.phar --config=psalm82.xml --no-cache
```

Fixing a baselined finding for real, not just suppressing it further, should also drop its
entry from the baseline file by hand (or regenerate with `--set-baseline=psalm82-baseline.xml`,
which overwrites the whole file with whatever's still outstanding — only do that once you've
actually looked at the diff, since it will just as happily paper over a new regression as
record a real fix).

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
