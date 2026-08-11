<?php
return [
    ''                      => 'frontend/',
    'login/'                => 'login',
    'register/'             => 'frontend/register',
    'logout/'               => 'frontend/logout',
    'recovery/'             => 'frontend/recovery',
    'recovery/<token>/'     => 'frontend/recovery',
    'callback/<method_id>/' => 'frontend/callback',
    'confirm/<token>/'      => 'frontend/confirm',
    'challenge/'            => 'frontend/challenge',
    // Section id pattern kept to the ids the registry can hold, so an address
    // that cannot name a section is a routing 404 and never reaches the app.
    // Widened to cover plugin section ids ('foo_plugin', 'oidc_plugin:gitlab' —
    // same shape as callback/<method_id>/ above) alongside core ids.
    // 'secure' buys both halves of the endpoint's protection: an unauthenticated
    // POST gets the login form, and the framework checks _csrf before dispatch.
    'my/save/<section:[a-z0-9_:-]+>/' => [
        'module' => 'frontend',
        'action' => 'mySave',
        'secure' => true,
    ],
    // Changing a login (email, phone) is proven before it is stored, see
    // authFrontendMyConfirmAction. Both halves are 'secure' on purpose: a
    // confirmation link travels by mail, and holding it alone must not be
    // enough to change an account's way in.
    'my/confirm/<token>/'   => [
        'module' => 'frontend',
        'action' => 'myConfirm',
        'secure' => true,
    ],
    'my/confirm/'           => [
        'module' => 'frontend',
        'action' => 'myConfirm',
        'secure' => true,
    ],
    'my/'                   => [
        'module' => 'frontend',
        'action' => 'my',
        'secure' => true,
    ],
];
