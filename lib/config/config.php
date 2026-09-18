<?php
return [
    // Активные методы входа. Порядок = порядок отображения.
    // Без суффикса → встроенный метод ('email', 'phone', 'waid').
    // С суффиксом _plugin → плагин: 'github_plugin' загрузит plugins/github/.
    // С ':' после суффикса → именованный инстанс multi_instance-плагина:
    // 'oidc_plugin:gitlab' и 'oidc_plugin:keycloak' — один код, разные настройки.
    //
    // Пусто по умолчанию: пока для домена не включён хотя бы один метод,
    // на этом сайте авторизации нет вовсе (см. authConfig::isEnabled).
    'login_methods' => [],

    // Второй фактор (challenge).
    // Плагин сам решает через isRequired($contact_id), нужен ли он конкретному пользователю.
    'challenge_methods' => [],

    // Guard-плагины: валидация перед созданием сессии или контакта.
    'guard_plugins' => [],

    // Собственные настройки плагинов, по плагину: ['blackmailguard' => ['rules' => [...]]]
    // У multi_instance-плагинов — блок на каждый именованный инстанс:
    // ['oidc' => ['gitlab' => [...], 'keycloak' => [...]]]
    'plugin_settings' => [],

    // Капча. null = без капчи. Один плагин на домен.
    'captcha_plugin' => null,

    // Режим показа капчи на форме входа (AUTH-49): 'off' — не показывать,
    // 'always' — как раньше, безусловно, 'after_n' — только после
    // captcha_after_n неудачных попыток по одному из счётчиков throttle.
    // На регистрации капча остаётся безусловной при любом режиме — см.
    // docs/adr/003-credential-throttle.md, решение 5.
    'captcha_mode'    => 'always',
    'captcha_after_n' => 3,

    // Защита от подбора (AUTH-49) — см. docs/adr/003-credential-throttle.md.
    // Включена из коробки: форма входа без этого не защищена вообще.
    'throttle_enabled' => true,

    // Порог и окно по введённому идентификатору (email/login/телефон, до
    // резолва в контакт) и отдельно по IP — два независимых счётчика,
    // не связка. Секунды.
    'throttle_login_attempts' => 5,
    'throttle_login_window'   => 900,
    'throttle_ip_attempts'    => 30,
    'throttle_ip_window'      => 900,

    // Растущая задержка перед следующей попыткой: attempts * throttle_delay
    // секунд с последней попытки. Секунды.
    'throttle_delay' => 2,

    // Та же растущая задержка, но для scope otp_send (authPhoneMethod)
    // отдельно от пароля: СМС стоит денег, и старое жёстко зашитое значение
    // (RESEND_COOLDOWN_SECONDS = 60) было настроено именно под этот расход,
    // а не под подбор пароля — общий throttle_delay для форм с паролем
    // специально мал (секунды), тут нужны минуты между повторными отправками.
    'throttle_otp_delay' => 60,

    // Жёсткая блокировка на throttle_lockout секунд после превышения порога.
    // Применяется только к счётчику по IP, никогда — по идентификатору: это
    // рычаг, которым атакующий, зная чужой email, выключал бы владельцу
    // вход, не зная пароля (решение 4 ADR). 0 = отключена.
    'throttle_lockout' => 0,

    // Хранилище счётчиков: id плагина (authThrottleStore), null = встроенное
    // (таблица auth_throttle). Пункт, который вообще плагинизируется —
    // см. authThrottleStore.
    'throttle_store' => null,

    // «Запомнить меня».
    'rememberme' => false,

    // Привязывать OAuth-вход к существующему паролевому аккаунту по совпадению
    // email. Небезопасно с провайдерами, не подтверждающими email (возможен
    // захват чужого аккаунта), поэтому по умолчанию выключено. Адаптеры,
    // явно подтверждающие email (напр. Webasyst ID), привязываются всегда —
    // независимо от этой настройки. Привязка по source_id провайдера — всегда.
    'oauth_link_by_email' => false,

    // Регистрация
    'signup_enabled' => false,
    'signup_methods' => [],
    'signup_confirm' => true,
    'signup_fields'  => ['firstname', 'lastname', 'email', 'password'],

    // Восстановление пароля (AUTH-48) — см. docs/adr/004-recovery-channels.md.
    'recovery_enabled' => true,

    // Каналы восстановления, в порядке проверки claims() (решение 3 ADR):
    // 'email' / 'phone' — встроенные, id плагина ('myplugin_plugin') — тот же
    // формат, что у login_methods. Не выводится из login_methods: включение
    // SMS-канала — отдельное решение админа (SIM swap иначе даёт доступ туда,
    // куда домен по SMS не пускает), а не следствие включённого OTP-входа.
    'recovery_channels' => ['email'],

    // Время жизни ссылки восстановления по email. Секунды.
    'recovery_link_ttl' => 3600,

    // Разрешить пользователю удалить свой аккаунт из личного кабинета.
    // Управляется здесь, а не картой сайта: personal_fields описывает, из каких
    // полей состоит профиль, и не имеет мнения о том, может ли профиль
    // перестать существовать (решение 1 в docs/adr/001-profile-config-boundaries.md).
    // Выключено по умолчанию: сайт, который об этом не думал, не должен это предлагать.
    'delete_account_enabled' => false,

    // Редиректы после действий. null = goal_url / HTTP_REFERER
    'redirect_after_login'    => null,
    'redirect_after_register' => null,
    'redirect_after_logout'   => '/',

    // URL страниц внутри приложения
    'login_url'    => 'login/',
    'register_url' => 'register/',
    'recovery_url' => 'recovery/',
];
