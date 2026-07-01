# Roadmap

Запланированные, но ещё не реализованные части приложения `auth`.
Подробности — в `auth-app-plan.md` / `auth-app-impl.md` в корне репозитория.

## Плагины

- [ ] GitHub OAuth (`plugins/github`)
- [ ] LDAP (`plugins/ldap`)
- [ ] Magic link (`plugins/magiclink`)
- [ ] TOTP — второй фактор (`plugins/totp`)
- [ ] Domain blacklist — guard (`plugins/domainblacklist`); сейчас есть только тестовый `testguard`
- [ ] reCAPTCHA — captcha (`plugins/recaptcha`)

## Уточнить

- [ ] Нужен ли отдельный `authFrontendLayout` (шаг 5 плана), или эту роль уже закрывает тема `themes/default` (head/header/footer/main)

## Итоговая проверка (сквозной прогон)

- [ ] Регистрация email + подтверждение письмом
- [ ] Вход email + пароль (обычный POST)
- [ ] Вход email + пароль (AJAX, модал)
- [ ] Восстановление пароля
- [ ] Вход через WAID (OAuth)
- [ ] Guard блокирует регистрацию
- [ ] Выход
- [ ] Личный кабинет — смена имени и фото
- [ ] Per-domain конфиг — два домена с разными `login_methods`
