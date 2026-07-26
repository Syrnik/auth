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
    // A login change waiting to be proven: the new email or phone the visitor
    // asked for, kept out of the contact until they confirm it on that very
    // value. See authProfileConfirmModel and decision 2 of
    // docs/adr/001-profile-config-boundaries.md.
    'auth_profile_confirm' => [
        'id'               => ['int', 11, 'null' => 0, 'autoincrement' => 1],
        'contact_id'       => ['int', 11, 'null' => 0],
        // Contact field the pending value belongs to: 'email' or 'phone'.
        'field'            => ['varchar', 32, 'null' => 0],
        'value'            => ['varchar', 255, 'null' => 0],
        'token'            => ['varchar', 64, 'null' => 0],
        // Only set for a value confirmed by a code the visitor types back
        // (phone). Stored hashed, never in the clear.
        'code_hash'        => ['varchar', 255, 'null' => 0, 'default' => ''],
        'attempts'         => ['int', 11, 'null' => 0, 'default' => 0],
        'created_datetime' => ['datetime', 'null' => 0],
        ':keys' => [
            'PRIMARY' => 'id',
            'token'   => ['token', 'unique' => 1],
            // One pending change per field: asking again replaces the request
            // instead of leaving two live tokens for the same login.
            'contact' => ['contact_id', 'field', 'unique' => 1],
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
