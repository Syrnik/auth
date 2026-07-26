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
    // 'secure' buys both halves of the endpoint's protection: an unauthenticated
    // POST gets the login form, and the framework checks _csrf before dispatch.
    'my/save/<section:[a-z_]+>/' => [
        'module' => 'frontend',
        'action' => 'mySave',
        'secure' => true,
    ],
    'my/'                   => [
        'module' => 'frontend',
        'action' => 'my',
        'secure' => true,
    ],
];
