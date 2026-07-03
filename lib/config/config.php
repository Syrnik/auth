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

    // Восстановление пароля
    'recovery_enabled' => true,

    // Редиректы после действий. null = goal_url / HTTP_REFERER
    'redirect_after_login'    => null,
    'redirect_after_register' => null,
    'redirect_after_logout'   => '/',

    // URL страниц внутри приложения
    'login_url'    => 'login/',
    'register_url' => 'register/',
    'recovery_url' => 'recovery/',
];
