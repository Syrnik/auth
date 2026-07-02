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
- **Captcha** — pluggable via `authCaptcha` interface
- **Design theme** — inherits `site:default`; auth pages look like part of the site
- **Per-domain settings** — stored in `wa-config/apps/auth/config.php`

## Requirements

- Webasyst Framework 4.0+
- PHP 7.4+

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
| `adapters` | `[]` | Per-domain OAuth adapter credentials (`app_id`/`app_secret`, etc.) |

Additional parameters can only be set in `lib/config/config.php` (or manually in `wa-config/apps/auth/config.php`):

| Parameter | Default | Description |
|---|---|---|
| `challenge_methods` | `[]` | Active second-factor plugins |
| `guard_plugins` | `[]` | Active guard plugins |
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
];
```

See `plugins/testguard/` for an example guard plugin that blocks signup only.

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
