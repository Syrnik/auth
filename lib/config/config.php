<?php
return [
    // Активные методы входа. Порядок = порядок отображения.
    // Без суффикса → встроенный метод ('email', 'phone', 'waid').
    // С суффиксом _plugin → плагин: 'github_plugin' загрузит plugins/github/.
    //
    // Пусто по умолчанию: пока для домена не включён хотя бы один метод,
    // на этом сайте авторизации нет вовсе (см. authConfig::isEnabled).
    'login_methods' => [],

    // Второй фактор (challenge).
    // Плагин сам решает через isRequired($contact_id), нужен ли он конкретному пользователю.
    'challenge_methods' => [],

    // Guard-плагины: валидация перед созданием сессии или контакта.
    'guard_plugins' => [],

    // Капча. null = без капчи. Один плагин на домен.
    'captcha_plugin' => null,

    // «Запомнить меня».
    'rememberme' => false,

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
