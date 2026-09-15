# Auth — Frontend Authentication App for Webasyst

*Читать на русском: [README.md](README.md)*

A frontend application for the Webasyst Framework that provides a full set of user-facing authentication pages: login, registration, password recovery, and a personal account. Easily extended with plugins.

## Features

- **Login** — email/password, login/password (`wa_contact.login`), Webasyst ID and any framework OAuth adapter (VK, Google, Facebook, etc. — wired up automatically, no plugin needed), phone (SMS OTP), third-party login methods via `authMethod` plugins
- **Registration** — with optional email confirmation
- **Password recovery** — token link sent by email
- **My account** (`/my/`) — profile editing
- **Two-factor authentication** — via `authChallenge` plugins
- **Guard plugins** — block login and/or signup based on any condition
- **Brute-force protection** (AUTH-49) — two independent counters (typed identifier and IP), escalating delay → captcha; the counter store is pluggable via `authThrottleStore`
- **Captcha** — pluggable via `authCaptcha` interface, with a display mode on the sign-in form (always / never / after N failed attempts)
- **Design theme** — inherits `site:default`; auth pages look like part of the site
- **Per-domain settings** — stored in `wa-config/apps/auth/config.php`

## Requirements

- Webasyst Framework 4.0+
- PHP 8.2–8.5

## Installation

1. Copy the `auth/` directory to `wa-apps/`.
2. Register the route in `wa-config/routing.php`:
   ```php
   'auth/*' => ['app' => 'auth'],
   ```
3. Go to the backend → **Auth** → **Settings** and choose the login methods.

## Configuration

Settings are stored per site (domain). There is no global "default" layer: a site either has its own configuration or has no authentication at all. When read, values are merged in two layers: distribution defaults from `lib/config/config.php` (per-field fallback) → the site's saved settings (`authConfig::getMerged()`). A site counts as "enabled" as soon as at least one login method is activated for it (`authConfig::isEnabled()`); otherwise `login/`, `register/` and `recovery/` return 404. The backend (**Auth → Settings**) edits a subset of these and saves to the `domains` key in `wa-config/apps/auth/config.php`.

Parameters editable in the backend:

| Parameter | Default | Description |
|---|---|---|
| `login_methods` | `[]` | Active login methods (order = display order). Empty → the site has no authentication |
| `signup_enabled` | `false` | Allow registration |
| `signup_confirm` | `true` | Require email confirmation on signup |
| `recovery_enabled` | `true` | Allow password recovery |
| `rememberme` | `false` | Show "Remember me" checkbox |
| `captcha_plugin` | `null` | Captcha plugin ID (or `null`) |
| `captcha_mode` | `'always'` | When to show the captcha on sign-in: `off` / `always` / `after_n`. Registration always shows it unconditionally regardless of this setting |
| `captcha_after_n` | `3` | Failed-attempt threshold for `after_n` mode |
| `throttle_enabled` | `true` | Brute-force protection (AUTH-49) is on for this domain |
| `throttle_login_attempts` / `throttle_login_window` | `5` / `900` | Threshold and window (seconds) by typed identifier |
| `throttle_ip_attempts` / `throttle_ip_window` | `30` / `900` | Threshold and window (seconds) by IP address |
| `throttle_delay` | `2` | Growing delay (seconds), `attempts × throttle_delay` |
| `throttle_lockout` | `0` | Hard lockout (seconds) once over threshold — IP only, `0` = disabled |
| `throttle_store` | `null` | Counter-store plugin ID (`authThrottleStore`), `null` = built-in (`auth_throttle` table) |
| `adapters` | `[]` | Per-domain OAuth adapter credentials (`app_id`/`app_secret`, etc.) |
| `guard_plugins` | `[]` | Active guard plugins ("Signup and login protection" section) |
| `challenge_methods` | `[]` | Active challenge plugins, second factor ("Two-factor authentication" section) |
| `plugin_settings` | `[]` | Plugins' own per-domain settings, keyed by plugin ID (see [plugin settings](#per-domain-plugin-settings)) |

Additional parameters can only be set in `lib/config/config.php` (or manually in `wa-config/apps/auth/config.php`):

| Parameter | Default | Description |
|---|---|---|
| `challenge_methods` | `[]` | Active second-factor plugins |
| `throttle_otp_delay` | `60` | Growing delay (seconds) for the `otp_send` scope (`authPhoneMethod`) — separate from `throttle_delay`: SMS costs money, so its step is larger than the one used against password guessing |
| `signup_methods` | `['email', 'waid']` | Methods offered on the registration form |
| `signup_fields` | `['firstname', 'lastname', 'email', 'password']` | Registration form fields |
| `redirect_after_login` / `redirect_after_register` / `redirect_after_logout` | `null` / `null` / `'/'` | Post-action redirects (`null` = `goal_url` / `HTTP_REFERER`) |
| `login_url` / `register_url` / `recovery_url` | `'login/'` / `'register/'` / `'recovery/'` | In-app page URLs |

## Plugin Development

Plugins live in `plugins/<plugin_id>/`. The main plugin class must extend `authPlugin` (itself a `waPlugin` subclass that adds `getTemplatePath()` for locating files under `templates/`). A plugin can implement one or more interfaces:

### `authMethod` — Login method

```php
class myPluginAuthMethod implements authMethod {
    public function authenticate(array $params): ?int { /* ... */ }
    public function handleCallback(array $params): authCallbackResult { /* ... */ }
    public function getCallbackUrl(): string { /* ... */ }
    public function getId(): string { return 'myplugin'; }
}
```

If the method is OAuth-style (`getInfo()['auth_type'] === 'oauth'`), route the identity it resolves through `authContactResolver::resolve($data)` rather than looking the contact up yourself. `resolve()` is also where the profile's "Linked accounts" section attaches an identity to the contact that is already signed in (AUTH-50) — a plugin that calls it gets that "Link" round trip for free; one that bypasses it only ever logs in or signs up, and its identities can never be linked to an existing account from the profile.

### `authGuard` — Block login or signup

```php
class myPluginAuthGuard implements authGuard {
    public function checkLogin(int $contact_id): void {
        // throw authGuardException to block
    }
    public function checkSignup(array $form_data): void { /* ... */ }
}
```

Multiple guard plugins may be active at once. They are called as a chain, in the exact order listed in `guard_plugins`; at each point (login/signup) only plugins with the matching `guard_login` / `guard_signup` flag participate. The first `authGuardException` thrown stops the chain and the whole request — remaining guards are not called, and the exception message is shown to the user. A guard cannot allow access, only pass (by not throwing) or block: the action proceeds only if every guard stays silent.

### `authChallenge` — Second factor

```php
class myPluginAuthChallenge implements authChallenge {
    public function isRequired(int $contact_id): bool { /* ... */ }
    public function verify(array $params): bool { /* ... */ }
    public function getId(): string { return 'myplugin'; }
}
```

### `authCaptcha` — Captcha

```php
class myPluginAuthCaptcha implements authCaptcha {
    public function renderWidget(): string { /* captcha HTML, empty string if no widget needed */ }
    public function verifyCaptcha(array $post): bool { /* ... */ }
}
```

### `authThrottleStore` — brute-force counter storage (AUTH-49)

The one pluggable part of brute-force protection — the thresholds, windows, delay and
captcha escalation themselves stay domain settings (`throttle_*`/`captcha_*` above), not a
plugin; see `docs/adr/003-credential-throttle.md`. A plugin is only needed when the
built-in store (the `auth_throttle` table) doesn't fit — for example, several app servers
that need to share one set of counters (Redis, memcached).

```php
class myPluginAuthThrottleStore implements authThrottleStore {
    public function getRow(string $scope, string $key_type, string $key_hash, string $window_start): ?array {
        /* ['attempts' => int, 'window_start' => 'Y-m-d H:i:s', 'last_attempt' => 'Y-m-d H:i:s'], or null */
    }
    public function hit(string $scope, string $key_type, string $key_hash, string $window_start): array {
        /* count one attempt and return the row in the same shape */
    }
    public function reset(string $scope, string $key_type, string $key_hash): void {
        /* clear every window of the key */
    }
}
```

`$key_hash` is already hashed (sha256) by the caller — the plugin never sees a raw
email/login/IP. Selected via the `throttle_store` setting (a plugin ID, `null` = the
built-in store), the same pattern as `captcha_plugin`.

Describe the plugin in `plugins/<plugin_id>/lib/config/plugin.php` — `authPluginManager` uses this file to determine which interfaces the plugin must implement, and verifies it on load (throwing otherwise):

```php
return [
    'name'         => 'My Plugin',
    'version'      => '1.0.0',
    'is_auth'      => true,   // implements authMethod
    'is_challenge' => true,   // implements authChallenge
    'is_guard'     => true,   // implements authGuard
    'guard_login'  => true,   // apply guard on login (is_guard only)
    'guard_signup' => true,   // apply guard on signup (is_guard only)
    'is_captcha'   => true,   // implements authCaptcha
    'is_throttle_store' => true, // implements authThrottleStore
];
```

See `plugins/testguard/` for an example guard plugin that blocks signup only. For a guard plugin with per-domain settings, see blackmailguard (email blacklist; lives in a separate repository, installs into `plugins/blackmailguard/`).

### Per-domain Plugin Settings

A plugin can keep its own settings separately for each site. They live in the same app config (`wa-config/apps/auth/config.php`), inside the domain section under the `plugin_settings` key:

```php
'domains' => [
    'example.com' => [
        // ...
        'plugin_settings' => [
            'myplugin' => ['rules' => ['*@spam.com']],
        ],
    ],
],
```

`authPlugin` provides three methods for this (all with safe defaults — a plugin without settings doesn't have to override anything):

```php
class authMypluginPlugin extends authPlugin implements authGuard
{
    // Controls for the backend settings screen. $settings — the domain's current settings.
    public function getSettingsControls(array $settings): array
    {
        return [
            'rules' => [
                'label' => 'Rules',
                'type'  => 'textarea',           // 'text' (default) or 'textarea'
                'value' => implode("\n", (array)($settings['rules'] ?? [])),
                'hint'  => 'Hint under the field', // optional
            ],
        ];
    }

    // POST from those controls → the array that gets stored in the config.
    // Normalization belongs here: textarea → array of lines, etc.
    public function prepareSettings(array $post): array
    {
        $lines = preg_split('~\R~u', (string)($post['rules'] ?? ''));
        return ['rules' => array_values(array_filter(array_map('trim', $lines), 'strlen'))];
    }

    public function checkSignup(array $form_data): void
    {
        // Read the current domain's settings (or pass a domain explicitly)
        $rules = (array)($this->getDomainSettings()['rules'] ?? []);
        // ...
    }
}
```

The backend settings screen currently renders these controls for guard plugins (the "Signup and login protection" section); the mechanism is shared, so challenge and captcha plugins can adopt the same three methods — only their sections on the settings screen remain to be rendered. Settings are saved even for disabled plugins: toggling a guard off and back on does not lose its rules.

## Design Theme

Templates are in `themes/default/`. The theme inherits from `site:default`, so auth pages automatically receive the site's header and footer. Users can edit templates through **Design → Auth** in the backend.

Key theme files:

| File | Purpose |
|---|---|
| `main.html` | Content wrapper (included by `site:default/index.html`) |
| `head.html` | CSS and JS injected into the site `<head>` |
| `header.html` | App navigation in the site header (empty for auth) |
| `footer.html` | App content in the site footer (empty for auth) |
| `login.html` | Login form (includes `<method>.login_form.html` for the active method) |
| `register.html` | Registration form |
| `register.confirm.html` | Email confirmation pending page |
| `recovery.html` | Password recovery form and new-password form |
| `challenge.html` | Two-factor authentication form |
| `my.profile.html` | Profile page |

## License

Webasyst End User License Agreement (EULA). See [LICENSE](LICENSE) for details (Russian version — [LICENSE_ru](LICENSE_ru)).
