# AGENTS.md

## Architecture Decisions

Application-level decisions that constrain the code live in `docs/adr/`. Read the
relevant record before changing the area it covers — these are the reasons behind
non-obvious constraints, and they are not re-derivable from the code alone.

- `001-profile-config-boundaries.md` — which config governs which block of the
  user profile (`my/`): site's `personal_fields` vs auth's `login_methods` vs the
  contact's actual linked accounts.

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
