# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- **`login` auth method** — login/password against the `wa_contact.login` field
- **Dynamic system OAuth adapters** — every framework-level adapter (VK, Google, Facebook, etc.) is now offered in settings automatically, with per-domain credential fields, instead of only Webasyst ID
- License files (Webasyst EULA) and contribution guidelines (`AGENTS.md`)

### Changed

- **WAID login** now goes through the auth app's own login pipeline instead of the framework's default redirect
- Built-in form methods (`email`, `login`, `phone`) are now derived from the method classes themselves rather than a separate, duplicated label list
- Login form templates decoupled per method, with a `<method>.login_form.html` partial for each

### Fixed

- Backend settings page lost its layout after the post-save redirect
- Signup guards are now checked before a contact is created, not after
- Registration link hidden on the login page when signup is disabled
- OAuth logins now respect `signup_enabled`; signup UI hidden when only OAuth methods are active
- OAuth/challenge login no longer redirects to a blank page when `redirect_after_login` is unset

### Security

- Post-authentication redirects are now confined to the current site. Both the user-supplied `goal_url` and the admin-configured `redirect_after_login` / `redirect_after_register` values pass through `authHelper::localRedirectUrl()`, closing an open-redirect / phishing vector (`//evil.com`, `/\evil.com`, absolute off-site URLs, `javascript:`, CR/LF injection)
- Password-recovery tokens moved out of `wa_app_settings` into a dedicated, self-expiring `auth_password_recovery` table; expired tokens are swept automatically so single-use secrets no longer accumulate indefinitely

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
