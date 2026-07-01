# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [0.1.0] - 2026-07-01

### Added

- **Login** — email/password form with CSRF protection; optional "Remember me"
- **Registration** — with optional email confirmation (token stored in `auth_signup_confirm` table)
- **Password recovery** — token sent by email, single-use link, stored in `wa_app_settings`
- **My profile** — built on top of `waMyProfileAction`; shows flash message on save
- **Two-factor authentication** — challenge step between credential verification and session creation; driven by `authChallenge` plugins
- **OAuth callback** — generic `authFrontendCallbackAction` handles redirect-back from any OAuth provider
- **Backend settings** — per-domain configuration UI; saves to `wa-config/apps/auth/config.php` via `waUtils::varExportToFile`
- **Plugin system**:
  - `authMethod` — pluggable login method (form-based or OAuth redirect)
  - `authGuard` — blocks login or signup with a user-visible error
  - `authCaptcha` — integrates any captcha solution
  - `authChallenge` — adds a second authentication factor
- **Built-in auth methods**: `email` (password), `waid` (Webasyst ID OAuth), `phone` (OTP stub)
- **Per-domain config** — settings merged in three layers: distribution defaults → global saved config → per-domain override
- **Design theme** — `themes/default/` inherits from `site:default` via `parent_theme_id`; provides `head.html`, `main.html`, `header.html`, `footer.html` and per-page templates
- **Example plugin** — `plugins/testguard/` demonstrates the `authGuard` interface
