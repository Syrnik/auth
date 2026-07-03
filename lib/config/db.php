<?php
return [
    'auth_signup_confirm' => [
        'id'               => ['int', 11, 'null' => 0, 'autoincrement' => 1],
        'contact_id'       => ['int', 11, 'null' => 0],
        'token'            => ['varchar', 64, 'null' => 0],
        'created_datetime' => ['datetime', 'null' => 0],
        ':keys' => [
            'PRIMARY' => 'id',
            'token'   => ['token', 'unique' => 1],
        ],
    ],
    'auth_password_recovery' => [
        'id'               => ['int', 11, 'null' => 0, 'autoincrement' => 1],
        'contact_id'       => ['int', 11, 'null' => 0],
        'token'            => ['varchar', 64, 'null' => 0],
        'created_datetime' => ['datetime', 'null' => 0],
        'expire_datetime'  => ['datetime', 'null' => 0],
        ':keys' => [
            'PRIMARY' => 'id',
            'token'   => ['token', 'unique' => 1],
        ],
    ],
];
