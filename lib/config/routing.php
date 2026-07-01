<?php
return [
    'login/'                => 'login',
    'register/'             => 'frontend/register',
    'logout/'               => 'frontend/logout',
    'recovery/'             => 'frontend/recovery',
    'recovery/<token>/'     => 'frontend/recovery',
    'callback/<method_id>/' => 'frontend/callback',
    'confirm/<token>/'      => 'frontend/confirm',
    'challenge/'            => 'frontend/challenge',
    'my/'                   => [
        'module' => 'frontend',
        'action' => 'my',
        'secure' => true,
    ],
];
