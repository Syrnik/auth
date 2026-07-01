# Auth — Frontend Authentication App for Webasyst

A frontend application for the Webasyst Framework that provides a full set of user-facing authentication pages: login, registration, password recovery, and a personal account. Easily extended with plugins.

## Features

- **Login** — email/password, Webasyst ID (OAuth), phone (OTP stub), third-party OAuth via plugins
- **Registration** — with optional email confirmation
- **Password recovery** — token link sent by email
- **My account** (`/my/`) — profile editing
- **Two-factor authentication** — via `authChallenge` plugins
- **Guard plugins** — block login or signup based on any condition
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

Settings are configured via the backend (**Auth → Settings**) and saved to `wa-config/apps/auth/config.php`. Different settings can be used for each domain.

| Parameter | Default | Description |
|---|---|---|
| `login_methods` | `['email', 'waid']` | Active login methods |
| `signup_enabled` | `true` | Allow registration |
| `signup_confirm` | `true` | Require email confirmation on signup |
| `recovery_enabled` | `true` | Allow password recovery |
| `rememberme` | `false` | Show "Remember me" checkbox |
| `captcha_plugin` | `null` | Captcha plugin ID (or `null`) |

## Plugin Development

Plugins live in `plugins/<plugin_id>/`. A plugin can implement one or more interfaces:

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
    public function render(): string { /* captcha HTML */ }
    public function verifyCaptcha(array $params): bool { /* ... */ }
}
```

Describe the plugin in `plugins/<plugin_id>/lib/config/plugin.php`:

```php
return [
    'name'       => 'My Plugin',
    'is_auth'    => true,   // auth method
    'is_guard'   => true,   // guard
    'is_captcha' => true,   // captcha
    'version'    => '1.0.0',
];
```

## Design Theme

Templates are in `themes/default/`. The theme inherits from `site:default`, so auth pages automatically receive the site's header and footer. Users can edit templates through **Design → Auth** in the backend.

Key theme files:

| File | Purpose |
|---|---|
| `main.html` | Content wrapper (included by `site:default/index.html`) |
| `head.html` | CSS and JS injected into the site `<head>` |
| `login.html` | Login form |
| `register.html` | Registration form |
| `register.confirm.html` | Email confirmation pending page |
| `recovery.html` | Password recovery form and new-password form |
| `challenge.html` | Two-factor authentication form |
| `my.profile.html` | Profile page |

## License

LGPL v3. See [LICENSE](LICENSE) for details.
