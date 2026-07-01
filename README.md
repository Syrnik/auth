# Auth — приложение авторизации для Webasyst

*Read this in English: [README.en.md](README.en.md)*

Фронтенд-приложение для Webasyst Framework, которое предоставляет полный набор страниц для работы с пользователями: вход, регистрация, восстановление пароля, личный кабинет. Легко расширяется плагинами.

## Возможности

- **Вход** — email/пароль, логин/пароль (`wa_contact.login`), Webasyst ID и любой OAuth-адаптер фреймворка (VK, Google, Facebook и т.д. — подключаются автоматически, без плагинов), телефон (OTP-код по SMS), сторонние методы входа через `authMethod`-плагины
- **Регистрация** — с опциональным подтверждением по email
- **Восстановление пароля** — ссылка с токеном на email
- **Личный кабинет** (`/my/`) — редактирование профиля
- **Двухфакторная аутентификация** — через `authChallenge`-плагины
- **Guard-плагины** — блокировка входа и/или регистрации по любому условию
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

Настройки складываются в три слоя: значения по умолчанию из `lib/config/config.php` → сохранённые глобальные значения → переопределение для конкретного домена (`authConfig::getMerged()`). Через бэкенд (меню **Auth → Настройки**) редактируется часть параметров, сохранение идёт в `wa-config/apps/auth/config.php`.

Параметры, доступные в бэкенде:

| Параметр | По умолчанию | Описание |
|---|---|---|
| `login_methods` | `['email', 'waid']` | Активные методы входа (порядок = порядок отображения) |
| `signup_enabled` | `true` | Разрешить регистрацию |
| `signup_confirm` | `true` | Требовать подтверждение email |
| `recovery_enabled` | `true` | Разрешить восстановление пароля |
| `rememberme` | `false` | Показывать «Запомнить меня» |
| `captcha_plugin` | `null` | ID капча-плагина (или `null`) |
| `adapters` | `[]` | Учётные данные OAuth-адаптеров (`app_id`/`app_secret` и т.д.) на домен |

Дополнительные параметры задаются только в `lib/config/config.php` (или вручную в `wa-config/apps/auth/config.php`):

| Параметр | По умолчанию | Описание |
|---|---|---|
| `challenge_methods` | `[]` | Активные плагины второго фактора |
| `guard_plugins` | `[]` | Активные guard-плагины |
| `signup_methods` | `['email', 'waid']` | Методы, доступные при регистрации |
| `signup_fields` | `['firstname', 'lastname', 'email', 'password']` | Поля формы регистрации |
| `redirect_after_login` / `redirect_after_register` / `redirect_after_logout` | `null` / `null` / `'/'` | Редиректы после действий (`null` = `goal_url` / `HTTP_REFERER`) |
| `login_url` / `register_url` / `recovery_url` | `'login/'` / `'register/'` / `'recovery/'` | URL страниц внутри приложения |

## Разработка плагинов

Плагины размещаются в `plugins/<plugin_id>/`. Главный класс плагина должен наследовать `authPlugin` (сам он наследует `waPlugin` и добавляет `getTemplatePath()` для поиска шаблонов в `templates/`). Плагин может реализовывать один или несколько интерфейсов:

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
    public function renderWidget(): string { /* HTML капчи, пустая строка если не нужен виджет */ }
    public function verifyCaptcha(array $post): bool { /* ... */ }
}
```

Описание плагина в `plugins/<plugin_id>/lib/config/plugin.php` — по этому файлу `authPluginManager` определяет, какие интерфейсы должен реализовывать плагин, и проверяет это при загрузке (иначе бросает исключение):

```php
return [
    'name'         => 'My Plugin',
    'version'      => '1.0.0',
    'is_auth'      => true,   // реализует authMethod
    'is_challenge' => true,   // реализует authChallenge
    'is_guard'     => true,   // реализует authGuard
    'guard_login'  => true,   // применять guard при входе (только для is_guard)
    'guard_signup' => true,   // применять guard при регистрации (только для is_guard)
    'is_captcha'   => true,   // реализует authCaptcha
];
```

Пример guard-плагина, блокирующего только регистрацию, — `plugins/testguard/`.

## Тема дизайна

Шаблоны находятся в `themes/default/`. Тема наследует `site:default`, поэтому страницы авторизации автоматически получают шапку и подвал сайта. Пользователь может отредактировать шаблоны через **Дизайн → Auth** в бэкенде.

Ключевые файлы темы:

| Файл | Назначение |
|---|---|
| `main.html` | Обёртка контента (включается из `site:default/index.html`) |
| `head.html` | CSS и JS в `<head>` сайта |
| `header.html` | Навигация приложения в хедере сайта (у auth пустая) |
| `footer.html` | Контент приложения в футере сайта (у auth пустой) |
| `login.html` | Форма входа (подключает `<method>.login_form.html` для активного метода) |
| `register.html` | Форма регистрации |
| `register.confirm.html` | Страница ожидания подтверждения email |
| `recovery.html` | Форма восстановления пароля и форма нового пароля |
| `challenge.html` | Форма двухфакторной аутентификации |
| `my.profile.html` | Страница профиля |

## Лицензия

LGPL v3. Подробнее — в файле [LICENSE](LICENSE).
