# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- **`login` auth method** — login/password against the `wa_contact.login` field
- **Dynamic system OAuth adapters** — every framework-level adapter (VK, Google, Facebook, etc.) is now offered in settings automatically, with per-domain credential fields, instead of only Webasyst ID
- License files (Webasyst EULA) and contribution guidelines (`AGENTS.md`)
- **Two-factor authentication settings** — `challenge_methods` (2FA plugins) can now be enabled per domain from the backend settings screen instead of hand-editing `config.php`
- **Captcha widget** wired into the login and registration forms, with per-domain settings (site key/secret, etc.) for captcha plugins on the backend settings screen
- Clean backend routing — `/settings/` and `/plugins/` replace the old `?module=backend&action=...` query URLs
- Last-visited domain remembered in an `auth_app_domain` cookie, so the bare app entry point returns to it instead of always defaulting to the first domain
- **Backend Plugins page** rebuilt on the standard Webasyst `waPluginsActions` screen: installed plugins get a real settings UI (`waPlugin::getSettings()`/`saveSettings()`) instead of a static read-only list

### Changed

- **Backend settings screen split into per-domain sections** — Authorization, Registration, Password recovery, Captcha, Protection, Two-factor authentication each get their own screen and URL (`settings/<domain>/<section>/`) instead of one long form; sidebar now shows a domain switcher on top and a static per-domain section list below it
- **Backend settings save via ajax** — saving a section swaps the response in place (with a "Saved" indicator) instead of a full-page redirect with `?saved=1`, matching the sidebar's existing ajax navigation
- Backend settings save now **merges into the domain's stored config** instead of overwriting it wholesale, so saving one section (e.g. Registration) can no longer clobber another section's settings — `plugin_settings` in particular is merged per plugin id, since Login/Captcha/Guards/Challenges all write into it
- **WAID login** now goes through the auth app's own login pipeline instead of the framework's default redirect
- Built-in form methods (`email`, `login`, `phone`) are now derived from the method classes themselves rather than a separate, duplicated label list
- Login form templates decoupled per method, with a `<method>.login_form.html` partial for each
- The JSON response terminator shared by the login and registration controllers is now a single `authJsonResponseTrait` instead of two copies
- `login.html` template variables are assembled once in `authHelper::loginViewData()`, used by both the login form action and the OAuth callback error path
- Backend settings form UI restructured with semantic field/value markup, `wa-checkbox`-styled checkboxes and `wa-select`-wrapped selects

### Fixed

- Backend settings page lost its layout after the post-save redirect
- Signup guards are now checked before a contact is created, not after
- Registration link hidden on the login page when signup is disabled
- OAuth logins now respect `signup_enabled`; signup UI hidden when only OAuth methods are active
- OAuth/challenge login no longer redirects to a blank page when `redirect_after_login` is unset
- Logout no longer emits an empty redirect when `redirect_after_logout` is stored as `null`; falls back to `/`
- OAuth `afterAuth()` returns explicitly after a blocked signup/login guard instead of reading possibly-uninitialized variables
- Registration rejects an email that already belongs to a user account instead of silently creating a second account that could never log in by email
- `authPluginManager::getSystemAdapters()` guards the adapters directory with `is_dir()` instead of silencing `scandir()` with `@`
- Registration now stops immediately on a failed captcha check, matching login — a guard error could otherwise silently overwrite the captcha error before it reached the user
- Backend Plugins page never got the app header/sidebar (missing `setLayout()`), fixed by replacing the whole screen with the standard plugin page above
- Frontend app root (`/auth/`) 404'd with "Empty module and/or action" — also broke the Design section's theme preview link, which points at this same URL. Now redirects to `my/` (logged in) or `login/` (guest)

### Security

- **Phone OTP hardening** — resends are throttled (60s cooldown, max 5 per flow) so the SMS channel can't be spammed, verification is capped at 5 attempts so a 6-digit code can't be brute-forced within its lifetime, and the code is stored hashed in the session instead of in plain text
- Email confirmation tokens are now issued and validated through `authSignupConfirmModel`, which sweeps expired rows on each new token — expired `auth_signup_confirm` rows no longer linger in the database

- Post-authentication redirects are now confined to the current site. Both the user-supplied `goal_url` and the admin-configured `redirect_after_login` / `redirect_after_register` values pass through `authHelper::localRedirectUrl()`, closing an open-redirect / phishing vector (`//evil.com`, `/\evil.com`, absolute off-site URLs, `javascript:`, CR/LF injection)
- Password-recovery tokens moved out of `wa_app_settings` into a dedicated, self-expiring `auth_password_recovery` table; expired tokens are swept automatically so single-use secrets no longer accumulate indefinitely
- OAuth identities are no longer auto-linked to an existing password account by a bare email match, which allowed account takeover via a provider that doesn't verify emails. Linking now requires either an adapter that vouches for the address (`email_verified` in the payload — set by the Webasyst ID adapter) or the new opt-in `oauth_link_by_email` setting; matching by the provider's own `source_id` is unaffected

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
