# AGENTS.md

## Commit Messages

All commits must follow the [Conventional Commits](https://www.conventionalcommits.org/en/v1.0.0/) specification.

Format: `<type>[optional scope]: <description>`

Common types: `feat`, `fix`, `chore`, `refactor`, `docs`, `test`, `ci`.

When the work relates to a task from the wa-tasks tracker, append the task
number to the end of the subject line as `task #<number>`, e.g.
`fix: validate redirect targets task #76.26`.

## Changelog

The `CHANGELOG.md` file must follow the [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) format.

- Add entries under `[Unreleased]` during development.
- On release, rename `[Unreleased]` to the version number with the release date.
- Sections within a version: `Added`, `Changed`, `Deprecated`, `Removed`, `Fixed`, `Security`.
