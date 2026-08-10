# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- **The rest of the profile sections** — photo, email, phone, address, password and account deletion, alongside the name and linked-accounts sections that came before them. The `my/` page is now assembled entirely from sections, each saved on its own
- **Changing a login is confirmed before it takes effect.** An email or a phone that a domain signs people in with is no longer stored on submit: the new value waits in `auth_profile_confirm` while a link goes to the new address or a code to the new number, and the old login keeps working until the visitor answers on the new one. Proof is always delivered to the new value — a message to the address already on file proves nothing about the one being moved to, and a typo would become the login. `my/confirm/` is the page that waits; `my/confirm/<token>/` is the link. A value already used by another account is refused before anything is sent
- **`login_email` and `login_phone` sections** — an email or phone that is an active login method is served by its own section class rather than by the contact-field one with a flag, per decision 2 of ADR 001. Each pair is mutually exclusive by availability, so a field never appears twice, and the credential half sits under "Sign-in and security" and never consults the site's `personal_fields`
- **Multi-value sections** — emails, phones and addresses are edited one value at a time, addressed by the contract's `index`. A value's inputs are rendered by the contact field itself, so a value edited alone posts under the name it would have had inside the whole-list form, and the section merges it back into the stored list before anything is written. Types (work, home, shipping) are editable, which the framework's own rendering leaves as a hidden field
- **`delete_account` section**, off by default and turned on per domain in the new backend **Profile** settings screen. The account is removed through the framework's own `waContact::delete()`, so every app clears what it stored about the person, and the deletion is the only thing behind one overridable method — a later policy (a grace period, a queue) replaces that method and inherits every guard above it
- `authProfileSection::getRedirectAfterSave()` — a save can end the page it was made on. Deleting the account is the case: redrawing the section afterwards would render it from a record that no longer exists
- **Section editing on the profile page without reloading it** — the `my/` page is now assembled from its sections' partials, and each section can be opened, saved and cancelled on its own. Every control is an ordinary link or form first: `my/?section=<id>&mode=view|edit` is a real page with that section opened, and the section form is a real POST answered with a redirect. The theme's `my.profile.js` intercepts both, asks the same addresses over fetch and puts the section the server sends back in place of the old one — so the markup a script leaves on the page is the markup a reload produces, with nothing rendered a second time in JS. Fetched with `X-Requested-With`, that same address answers with the section partial alone
- **Profile page behavior lives in the theme.** The app answers with markup and never decides where it goes: `my.profile.js` is a theme file, and a theme that wants a drawer, an accordion or a field that turns into an input on click edits it instead of hooking into the app. What the app guarantees is the interface behind it — the two addresses above, the response envelope, and the template variables every section partial gets in both modes — documented in README under «Тема дизайна» so that changing the behavior takes no reading of app code
- **Per-section profile save endpoint** — `POST my/save/<section>/` writes one section of the `my/` page and answers with that section's rendered partial: view mode when the save went through, edit mode with the submitted values and the errors when it did not. Saving one section no longer touches the fields of the others, and a partial submit no longer fails validation on required fields it never carried. Without JS the same endpoint is an ordinary post/redirect/get, and a failed save is restored on the page it redirects to
- **`authProfileSectionConfirmable`** — a section whose new value must be proven before it replaces the old one (an email or phone that is a login method, the password) answers the save endpoint with the URL of its confirmation flow instead of being stored, per decision 2 of ADR 001
- **Profile section contract** (`authProfileSection`) and section registry — the `my/` page is described as a list of independently editable sections, each owning the condition of its own existence and telling "unavailable" from "available but empty"; the action hands the theme a ready-made list grouped for rendering, and a group with no available sections disappears on its own
- **`login` auth method** — login/password against the `wa_contact.login` field
- **Dynamic system OAuth adapters** — every framework-level adapter (VK, Google, Facebook, etc.) is now offered in settings automatically, with per-domain credential fields, instead of only Webasyst ID
- License files (Webasyst EULA) and contribution guidelines (`AGENTS.md`)
- Architecture decision records in `docs/adr/`, starting with the config boundaries of the user profile page (`my/`)
- **Two-factor authentication settings** — `challenge_methods` (2FA plugins) can now be enabled per domain from the backend settings screen instead of hand-editing `config.php`
- **Captcha widget** wired into the login and registration forms, with per-domain settings (site key/secret, etc.) for captcha plugins on the backend settings screen
- Clean backend routing — `/settings/` and `/plugins/` replace the old `?module=backend&action=...` query URLs
- Last-visited domain remembered in an `auth_app_domain` cookie, so the bare app entry point returns to it instead of always defaulting to the first domain
- **Backend Plugins page** rebuilt on the standard Webasyst `waPluginsActions` screen: installed plugins get a real settings UI (`waPlugin::getSettings()`/`saveSettings()`) instead of a static read-only list

### Changed

- **Unlinking an account now counts phone OTP as a way back in.** ADR 001 had left it out on purpose while the phone section did not exist and the answer would have been a guess; with that section written, "does this contact have a number the domain signs people in with" has an exact answer, and a lock that refuses a legitimate unlink is not a safer lock. The password is still counted when unlinking and still not counted when removing a login value — removing the email a password is checked against takes the password with it
- Contact-field sections gained two shaping hooks around validation, `prepareData()` and `prepareForStorage()`, mirroring the order the framework itself uses: phone numbers are normalized before they are validated, addresses reshaped after. Multi-value sections merge the submitted value into the stored list in the first of them
- **A value's type survives a save that renumbers the list.** The framework recovers an address type by looking up the same position in the contact afterwards, which holds only while positions do not move — removing the first of three values would file the other two under their neighbours' types. The type is now attached while the answer is still known
- **`my.profile.html` no longer asks the contact form what to show.** The theme walks the groups and sections the action hands it and renders their `html`; it holds no field checks of its own. Visibility comes from three unrelated config sources, and a template answering those questions for itself drifts apart from the app the moment any of them changes — see ADR 001. Sections get their own addresses (`$save_url`, `$edit_url`, `$view_url`) as template variables rather than composing them in Smarty
- `authProfileSection` gained `restoreFailedSave()`: without JS a failed save answers with a redirect, and the request that draws the profile page afterwards has neither the submitted values nor the errors. Sections built out of contact fields put both back into their form; a section without a form inherits a default that restores the errors alone
- **Backend settings screen split into per-domain sections** — Authorization, Registration, Password recovery, Captcha, Protection, Two-factor authentication each get their own screen and URL (`settings/<domain>/<section>/`) instead of one long form; sidebar now shows a domain switcher on top and a static per-domain section list below it
- **Backend settings save via ajax** — saving a section swaps the response in place (with a "Saved" indicator) instead of a full-page redirect with `?saved=1`, matching the sidebar's existing ajax navigation
- Backend settings save now **merges into the domain's stored config** instead of overwriting it wholesale, so saving one section (e.g. Registration) can no longer clobber another section's settings — `plugin_settings` in particular is merged per plugin id, since Login/Captcha/Guards/Challenges all write into it
- **WAID login** now goes through the auth app's own login pipeline instead of the framework's default redirect
- Built-in form methods (`email`, `login`, `phone`) are now derived from the method classes themselves rather than a separate, duplicated label list
- Login form templates decoupled per method, with a `<method>.login_form.html` partial for each
- The JSON response terminator shared by the login and registration controllers is now a single `authJsonResponseTrait` instead of two copies
- `login.html` template variables are assembled once in `authHelper::loginViewData()`, used by both the login form action and the OAuth callback error path
- Backend settings form UI restructured with semantic field/value markup, `wa-checkbox`-styled checkboxes and `wa-select`-wrapped selects

### Removed

- **`authContactForm`** — the class existed to serve one whole-page profile form: `hasField()` let a template ask whether a field was there, `hasOheOfNameFields()` did the same for the three name fields, and `htmlAllExcept()` rendered everything the theme had not laid out by hand. With `my/` assembled from sections none of it has a caller — a section knows its own fields, and the theme gets a ready-made list of sections from the action and asks nothing. What was left, `fromForm()`, only copied a `waContactForm` into a subclass that no longer added anything to it, so the class went with the methods. A theme that still calls any of the three has kept a copy of the app's field logic and belongs on the section list instead

### Fixed

- Bare backend entry (`webasyst/auth/` with no domain in the path) redirected to the frontend `my/` page instead of the backend dashboard — `routing.backend.php` and `routing.php` both used the literal `''` array key for their own root rule, and `waAppConfig::getRoutingRules()` always merges `routing.php` on top of `routing.backend.php` in the backend environment, so the frontend rule silently won. `routing.backend.php`'s root rule is now `'/?'` — same effective pattern, no longer a colliding key
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
- `my/` accepted a `POST` and wrote the contact through `waMyProfileAction::saveFromPost()` — a second, live write path bypassing every rule that `my/save/<section>/` enforces (login-change confirmation, current-password check, sections' `prepareData()`/`prepareForStorage()`, save logging). `authFrontendMyAction` no longer extends `waMyProfileAction`; `POST my/` now answers `405`, and it no longer assembles a whole-page `waContactForm` on every view or hands the theme `form`/`user_info`/`contact`, none of which it read
- `themes/default/theme.xml` only declared 4 of the 21 files behind the `my/` profile page (the `name` and `linked_accounts` partials from an earlier stage). The 17 partials added since — `photo`, `email`, `phone`, `address`, `login_email`, `login_phone`, `password`, `delete_account` (view/edit each) and `my.confirm.html` — were invisible in the design editor's file list even though they worked on disk, so a theme couldn't override one of them the way ADR 001 §6 promises

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
