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

Настройки задаются отдельно для каждого сайта (домена). Глобальных настроек «по умолчанию» нет: сайт либо имеет собственную конфигурацию, либо авторизации на нём нет вовсе. При чтении значения складываются в два слоя: значения по умолчанию из `lib/config/config.php` (fallback на уровне отдельных полей) → сохранённые настройки домена (`authConfig::getMerged()`). Сайт считается «включённым», как только для него активирован хотя бы один метод входа (`authConfig::isEnabled()`); иначе страницы `login/`, `register/` и `recovery/` отдают 404. Через бэкенд (меню **Auth → Настройки**) редактируется часть параметров, сохранение идёт в `wa-config/apps/auth/config.php` в ключ `domains`.

Параметры, доступные в бэкенде:

| Параметр | По умолчанию | Описание |
|---|---|---|
| `login_methods` | `[]` | Активные методы входа (порядок = порядок отображения). Пусто → на сайте нет авторизации. Формат id: `email` (встроенный), `github_plugin` (плагин), `oidc_plugin:gitlab` (именованный инстанс multi-instance-плагина) |
| `signup_enabled` | `false` | Разрешить регистрацию |
| `signup_confirm` | `true` | Требовать подтверждение email |
| `recovery_enabled` | `true` | Разрешить восстановление пароля |
| `rememberme` | `false` | Показывать «Запомнить меня» |
| `delete_account_enabled` | `false` | Разрешить пользователю удалить свой аккаунт из `my/` (секция «Профиль»). Удаление жёсткое и необратимое |
| `captcha_plugin` | `null` | ID капча-плагина (или `null`) |
| `adapters` | `[]` | Учётные данные OAuth-адаптеров (`app_id`/`app_secret` и т.д.) на домен |
| `guard_plugins` | `[]` | Активные guard-плагины (секция «Signup and login protection») |
| `challenge_methods` | `[]` | Активные challenge-плагины, второй фактор (секция «Two-factor authentication») |
| `plugin_settings` | `[]` | Собственные настройки плагинов на домен, по ID плагина (см. [настройки плагина](#настройки-плагина-на-домен)) |

Дополнительные параметры задаются только в `lib/config/config.php` (или вручную в `wa-config/apps/auth/config.php`):

| Параметр | По умолчанию | Описание |
|---|---|---|
| `challenge_methods` | `[]` | Активные плагины второго фактора |
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

Guard-плагинов может быть несколько. Они вызываются цепочкой, строго в порядке перечисления в `guard_plugins`; для каждой точки (вход/регистрация) участвуют только плагины с соответствующим флагом `guard_login` / `guard_signup`. Первый выброшенный `authGuardException` останавливает цепочку и всю обработку — остальные guard-плагины не вызываются, а сообщение из исключения показывается пользователю. Разрешить вход guard не может, только пропустить дальше (ничего не бросив) или заблокировать: действие выполняется, лишь если промолчали все.

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
    'name'                 => 'My Plugin',
    'version'              => '1.0.0',
    'is_auth'              => true,   // реализует authMethod
    'is_challenge'         => true,   // реализует authChallenge
    'is_guard'             => true,   // реализует authGuard
    'guard_login'          => true,   // применять guard при входе (только для is_guard)
    'guard_signup'         => true,   // применять guard при регистрации (только для is_guard)
    'is_captcha'           => true,   // реализует authCaptcha
    'auth_type'            => 'oauth', // OAuth-метод: кнопка вместо формы (только для is_auth)
    'multi_instance'       => true,   // поддержка именованных инстансов (см. ниже)
    'has_profile_section'  => true,   // реализует authProfileSectionProvider (блок в my/, см. ниже)
];
```

Пример guard-плагина, блокирующего только регистрацию, — `plugins/testguard/`. Пример guard-плагина с per-domain настройками — blackmailguard (чёрный список email; живёт в отдельном репозитории, устанавливается в `plugins/blackmailguard/`).

### Блок плагина в профиле (`my/`)

Плагину, которому нужен свой блок в личном кабинете (экран подключения 2FA и т. п.), не
нужен ни новый интерфейс, ни новый route — см. `docs/adr/001-profile-config-boundaries.md`,
решение 7. Плагин реализует `authProfileSectionProvider`:

```php
class authMyPlugin extends authPlugin implements authProfileSectionProvider
{
    public function getProfileSection(string $section_id, ?waContact $contact = null): ?authProfileSection
    {
        return new authMyPluginProfileSection($this, $section_id, $contact);
    }
}
```

`$section_id` — не то, что плагин выбирает: это config id, под которым `authPluginManager`
нашёл плагин в доменных списках (`login_methods`/`challenge_methods`/`guard_plugins`/
`captcha_plugin`) — так исключается перехват чужого id (`password` и т. п.) и, как
следствие, чужого адреса сохранения.

Сама секция — обычный `authProfileSection`, тот же контракт, что у секций ядра; удобнее
всего наследовать `authProfileSectionPlugin`
(`lib/classes/profile/authProfileSectionPlugin.class.php`), которая уже знает, как искать
партиал и в какую группу падать по умолчанию (`authorization`). Партиал ищется по имени
`my.profile.<section_id>.<mode>.html` — сперва в активной теме (тема может переопределить
и плагинный партиал так же, как ядровой), затем в `templates/` самого плагина; `:` в
`section_id` (именованный инстанс, `oidc_plugin:gitlab`) в имени файла заменяется на `-`.

Флаг `has_profile_section` проверяется по тем же доменным спискам, что обнаружение
`is_guard`/`is_challenge`/`is_captcha`, а не по всем установленным плагинам — иначе
секция появлялась бы независимо от того, включён ли плагин на этом домене. Отсюда
следствие: **плагин без роли `is_auth`/`is_challenge`/`is_guard`/`is_captcha` ни в одном
доменном списке не значится и свой блок в `my/` показать не может**, даже с
`has_profile_section => true`, — своего списка на «просто есть блок в профиле» не заводится.

Ещё одна тихая особенность: `getGroups()` пропускает секцию, чья `getGroup()` возвращает id
не из `authProfileSectionRegistry::getGroupNames()` — опечатка в имени группы даёт не
ошибку, а невидимый блок.

Эталон — `plugins/totp/`: `authTotpPlugin implements authChallenge,
authProfileSectionProvider`, секция `authTotpProfileSection` (подключить / подтвердить
кодом / отключить), партиалы `plugins/totp/templates/my.profile.{view,edit}.html`.

### Настройки плагина на домен

Плагин может хранить собственные настройки отдельно для каждого сайта. Они лежат в том же конфиге приложения (`wa-config/apps/auth/config.php`), внутри секции домена в ключе `plugin_settings`:

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

Для работы с ними `authPlugin` даёт три метода (все с безопасными реализациями по умолчанию — плагин без настроек ничего переопределять не обязан):

```php
class authMypluginPlugin extends authPlugin implements authGuard
{
    // Поля для экрана настроек в бэкенде. $settings — текущие настройки домена.
    public function getSettingsControls(array $settings): array
    {
        return [
            'rules' => [
                'label' => 'Правила',
                'type'  => 'textarea',            // 'text' (по умолчанию) или 'textarea'
                'value' => implode("\n", (array)($settings['rules'] ?? [])),
                'hint'  => 'Подсказка под полем', // необязательно
            ],
        ];
    }

    // POST из этих полей → массив, который будет сохранён в конфиг.
    // Здесь же нормализация: textarea → массив строк и т.п.
    public function prepareSettings(array $post): array
    {
        $lines = preg_split('~\R~u', (string)($post['rules'] ?? ''));
        return ['rules' => array_values(array_filter(array_map('trim', $lines), 'strlen'))];
    }

    public function checkSignup(array $form_data): void
    {
        // Чтение настроек текущего домена (или явно указанного вторым аргументом)
        $rules = (array)($this->getDomainSettings()['rules'] ?? []);
        // ...
    }
}
```

Экран настроек в бэкенде отображает эти поля для guard-плагинов (секция «Signup and login protection») и auth-плагинов (секция «Login methods»); механизм общий, так что challenge- и captcha-плагины смогут использовать те же три метода — потребуется только отрисовать их секции на экране настроек. Настройки сохраняются и для выключенных плагинов: выключение и повторное включение guard-плагина не теряет его правила.

### Именованные инстансы (multi_instance)

Плагин с `'multi_instance' => true` в `plugin.php` можно включить на одном сайте несколько раз с разными настройками — например, generic OIDC-плагин с кнопками «Войти через GitLab» и «Войти через Keycloak» от одного и того же кода. Каждое подключение — именованный инстанс со своим ключом (`[a-z0-9][a-z0-9_-]*`):

- в `login_methods` (а также `guard_plugins`/`challenge_methods`/`captcha_plugin`) инстанс записывается как `<plugin_id>_plugin:<ключ>`: `oidc_plugin:gitlab`, `oidc_plugin:keycloak`;
- в `plugin_settings` у такого плагина — по блоку настроек на инстанс: `'oidc' => ['gitlab' => [...], 'keycloak' => [...]]`;
- `authPluginManager` загружает и кеширует каждый инстанс отдельно; внутри плагина ключ доступен через `$this->getInstance()` (у обычных плагинов — `null`), а полный id метода — через `$this->getMethodId()` (`oidc_plugin:gitlab`) — его же нужно возвращать из `authMethod::getId()` и использовать в callback-URL;
- `getDomainSettings()` возвращает срез настроек текущего инстанса — код плагина одинаково работает и с инстансами, и без них;
- если среди контролов настроек есть поле `name`, его значение используется как название метода на странице входа (`getName()`), чтобы кнопки инстансов различались;
- экран настроек в бэкенде для такого плагина показывает список инстансов с добавлением и удалением вместо одного чекбокса.

Плагины без `multi_instance` ничего не замечают: id без `:` работают как раньше, а id с `:` для них считаются ошибкой и не загружаются. Пример multi-instance-плагина — `plugins/testmulti/` (тестовая заглушка без реального провайдера).

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
| `my.profile.<секция>.<режим>.html` | Партиал одной секции профиля в одном режиме (`view` / `edit`); для секции плагина `<секция>` — её id с `:` (именованный инстанс) заменённым на `-`, и тема переопределяет этот файл точно так же, как ядровой |
| `my.profile.js` | Поведение страницы профиля: редактирование секций без перезагрузки |
| `my.confirm.html` | Подтверждение смены логина: ввод кода либо «письмо отправлено» |

### Страница профиля

Страница `my/` собирается из секций — независимо редактируемых блоков. Какие секции существуют и какие из них доступны, решает приложение: видимость складывается из трёх независимых источников (`personal_fields` сайта, `login_methods` домена, реально привязанные аккаунты контакта), и `my.profile.html` их не проверяет — он обходит готовый массив `$profile_groups` и выводит `html` каждой секции.

| Секция | Группа | Доступна, когда |
|---|---|---|
| `photo` | Профиль | `personal_fields` разрешает `photo` |
| `name` | Профиль | `personal_fields` разрешает хотя бы одно из имён |
| `email` | Профиль | `personal_fields` разрешает `email` **и** email не метод входа |
| `phone` | Профиль | `personal_fields` разрешает `phone` **и** телефон не метод входа |
| `address` | Профиль | `personal_fields` разрешает `address` |
| `login_email` | Вход и безопасность | email — активный метод входа |
| `login_phone` | Вход и безопасность | телефон — активный метод входа |
| `password` | Вход и безопасность | среди методов входа есть паролевый |
| `linked_accounts` | Вход и безопасность | домен предлагает хотя бы один OAuth-метод |
| `delete_account` | Вход и безопасность | включена настройка `delete_account_enabled` |

Пары `email` / `login_email` и `phone` / `login_phone` взаимоисключающие: одно и то же поле никогда не выводится дважды. Учётные секции (`login_*`, `password`, `linked_accounts`, `delete_account`) не смотрят в `personal_fields` вовсе — см. `docs/adr/001-profile-config-boundaries.md`.

Многозначные секции (`email`, `phone`, `address`, `login_*`) редактируются по одному значению: адрес значения — `?section=<id>&mode=edit&index=<N>`, а `index`, равный длине списка, означает «добавить». Пустое значение удаляет запись, если `getRemovalLock()` не запрещает.

### Смена логина

Email или телефон, которым домен пускает в аккаунт, не сохраняется по сабмиту. Секция отвечает адресом потока, значение ждёт в `auth_profile_confirm`, а на **новое** значение уходит письмо со ссылкой или SMS с кодом. Старый логин работает, пока новый не подтверждён.

| Адрес | Что делает |
|---|---|
| `my/confirm/?field=email\|phone` | Страница ожидания: форма кода либо «письмо отправлено», плюс отмена |
| `my/confirm/<token>/` | Ссылка из письма: применяет смену и возвращает на профиль |

Оба адреса требуют входа: смена учётных данных не должна проходить по одной лишь перехваченной ссылке. Значение, уже занятое другим аккаунтом, отвергается до отправки и ещё раз в момент применения — между этими моментами проходит время.

Разметку секции задаёт тема, по файлу на режим: `my.profile.<секция>.<режим>.html`. Тема вправе переопределить один партиал, не копируя остальные, и вправе сделать оба режима одинаковыми — что считать «просмотром», а что «редактированием», решает она.

Переменные, доступные в партиале секции (одни и те же в обоих режимах):

| Переменная | Значение |
|---|---|
| `$section` | Объект секции: `getId()`, `getName()`, `isEmpty()`, `isMultiple()`, `getRemovalLock()` |
| `$mode` | `view` или `edit` |
| `$index` | Номер значения для многозначных секций, иначе `null` |
| `$form` | `waContactForm` только из полей этой секции, либо `null` (связанные аккаунты, удаление) |
| `$errors` | Ошибки последнего сохранения: `field_id => [сообщения]`, ключ `''` — не привязанные к полю |
| `$save_url` | Куда секция сохраняется |
| `$edit_url`, `$view_url` | Адрес страницы профиля с этой секцией в соответствующем режиме |
| `$contact` | Контакт, чей профиль показывается |

Многозначные секции добавляют к ним: `$values` — весь список, `$value` — значение по текущему индексу, `$field_html` — готовая разметка его полей, `$ext_options` — типы значения, `$next_index` и `$removal_lock`. Адреса отдельных значений даёт секция: `$section->getValueEditUrl($i)` и `$section->getAddUrl()` — индекс едет в URL, и составлять его в Smarty означало бы, что об этом должна знать каждая тема. Логин-секции добавляют `$pending` (ожидающая подтверждения смена), `$login_value`, `$uses_code` и `$is_login`.

Приложение отвечает разметкой и никогда не решает, куда её поместить, — это дело темы. Интерфейс, на который она может опираться:

| Запрос | Ответ |
|---|---|
| `GET my/?section=<id>&mode=view\|edit` | Страница профиля с этой секцией в нужном режиме |
| то же с `X-Requested-With: XMLHttpRequest` | Только партиал секции, как HTML (404, если секции нет) |
| `POST my/save/<id>/` | Редирект на `my/` (без XHR) либо `{status, data: {section, index, mode, html \| redirect}}` |
| `POST my/` | `405` — профиль пишется только через `my/save/<section>/` |

Всё на странице работает без JavaScript: «Изменить» и «Отмена» — обычные ссылки, форма секции — обычный POST с редиректом, а неудачное сохранение переживает редирект и возвращается с введёнными значениями и ошибками. `my.profile.js` перехватывает клик и сабмит, запрашивает те же адреса через `fetch` и подменяет секцию ответом сервера; при любом сбое — обычный переход. Разметку он не строит: секция рисуется ровно в одном месте, в своём партиале.

Скрипт опирается на три атрибута — `data-section` на корне партиала (этот элемент и подменяется), `data-section-link` на ссылке, `data-section-form` на форме. Он лежит в теме, поэтому меняется целиком: чтобы открывать секции в drawer'е или превращать поле в инпут по клику, правится этот файл, а не приложение.

## Лицензия

Лицензионное соглашение конечного пользователя Webasyst (Webasyst EULA). Подробнее — в файле [LICENSE_ru](LICENSE_ru) (английская версия — [LICENSE](LICENSE)).
