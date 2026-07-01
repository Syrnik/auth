# Auth — приложение авторизации для Webasyst

Фронтенд-приложение для Webasyst Framework, которое предоставляет полный набор страниц для работы с пользователями: вход, регистрация, восстановление пароля, личный кабинет. Легко расширяется плагинами.

## Возможности

- **Вход** — email/пароль, Webasyst ID (OAuth), телефон (OTP-заглушка), сторонние OAuth-провайдеры через плагины
- **Регистрация** — с опциональным подтверждением по email
- **Восстановление пароля** — ссылка с токеном на email
- **Личный кабинет** (`/my/`) — редактирование профиля
- **Двухфакторная аутентификация** — через `authChallenge`-плагины
- **Guard-плагины** — блокировка входа или регистрации по любому условию
- **Капча** — подключаемая через `authCaptcha`-плагин
- **Тема дизайна** — наследует `site:default`; страницы авторизации выглядят как часть сайта
- **Настройки на домен** — конфигурация хранится в `wa-config/apps/auth/config.php`

## Требования

- Webasyst Framework 4.0+
- PHP 7.4+

## Установка

1. Скопируйте директорию `auth/` в `wa-apps/`.
2. Зарегистрируйте маршрут в `wa-config/routing.php`:
   ```php
   'auth/*' => ['app' => 'auth'],
   ```
3. Зайдите в бэкенд → **Auth** → **Настройки** и выберите нужные методы входа.

## Конфигурация

Настройки задаются через бэкенд (меню **Auth → Настройки**) и сохраняются в `wa-config/apps/auth/config.php`. Поддерживаются разные настройки для каждого домена.

| Параметр | По умолчанию | Описание |
|---|---|---|
| `login_methods` | `['email', 'waid']` | Активные методы входа |
| `signup_enabled` | `true` | Разрешить регистрацию |
| `signup_confirm` | `true` | Требовать подтверждение email |
| `recovery_enabled` | `true` | Разрешить восстановление пароля |
| `rememberme` | `false` | Показывать «Запомнить меня» |
| `captcha_plugin` | `null` | ID капча-плагина (или `null`) |

## Разработка плагинов

Плагины размещаются в `plugins/<plugin_id>/`. Плагин может реализовывать один или несколько интерфейсов:

### `authMethod` — метод входа

```php
class myPluginAuthMethod implements authMethod {
    public function authenticate(array $params): ?int { /* ... */ }
    public function handleCallback(array $params): authCallbackResult { /* ... */ }
    public function getCallbackUrl(): string { /* ... */ }
    public function getId(): string { return 'myplugin'; }
}
```

### `authGuard` — блокировка входа/регистрации

```php
class myPluginAuthGuard implements authGuard {
    public function checkLogin(int $contact_id): void {
        // throw authGuardException to block
    }
    public function checkSignup(array $form_data): void { /* ... */ }
}
```

### `authChallenge` — второй фактор

```php
class myPluginAuthChallenge implements authChallenge {
    public function isRequired(int $contact_id): bool { /* ... */ }
    public function verify(array $params): bool { /* ... */ }
    public function getId(): string { return 'myplugin'; }
}
```

### `authCaptcha` — капча

```php
class myPluginAuthCaptcha implements authCaptcha {
    public function render(): string { /* HTML капчи */ }
    public function verifyCaptcha(array $params): bool { /* ... */ }
}
```

Описание плагина в `plugins/<plugin_id>/lib/config/plugin.php`:

```php
return [
    'name'       => 'My Plugin',
    'is_auth'    => true,   // метод входа
    'is_guard'   => true,   // guard
    'is_captcha' => true,   // капча
    'version'    => '1.0.0',
];
```

## Тема дизайна

Шаблоны находятся в `themes/default/`. Тема наследует `site:default`, поэтому страницы авторизации автоматически получают шапку и подвал сайта. Пользователь может отредактировать шаблоны через **Дизайн → Auth** в бэкенде.

Ключевые файлы темы:

| Файл | Назначение |
|---|---|
| `main.html` | Обёртка контента (включается из `site:default/index.html`) |
| `head.html` | CSS и JS в `<head>` сайта |
| `login.html` | Форма входа |
| `register.html` | Форма регистрации |
| `register.confirm.html` | Страница ожидания подтверждения email |
| `recovery.html` | Форма восстановления пароля и форма нового пароля |
| `challenge.html` | Форма двухфакторной аутентификации |
| `my.profile.html` | Страница профиля |

## Лицензия

LGPL v3. Подробнее — в файле [LICENSE](LICENSE).
