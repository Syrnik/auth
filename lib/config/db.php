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
    // Brute-force throttle counters (AUTH-49): one row per (key, scope, window),
    // not one row per attempt — see authThrottleModel and
    // docs/adr/003-credential-throttle.md, decision 8. key_hash is a sha256 of
    // the normalized key (an IP, or a login/email/phone as typed) — never the
    // raw value, since in the clear it's a contact's email or phone.
    'auth_throttle' => [
        'id'           => ['int', 11, 'null' => 0, 'autoincrement' => 1],
        'key_hash'     => ['varchar', 64, 'null' => 0],
        // 'ip' or 'login' (the identifier as typed, before it resolves to a contact).
        'key_type'     => ['varchar', 16, 'null' => 0],
        // 'login' / 'signup' / 'recovery' / 'otp_send' — what is being limited.
        'scope'        => ['varchar', 32, 'null' => 0],
        'window_start' => ['datetime', 'null' => 0],
        // Not derivable from window_start + attempts: backs the refusal window
        // (throttle_delay) and retry_after, which need the time of the most
        // recent attempt, not the time the window opened.
        'last_attempt' => ['datetime', 'null' => 0],
        'attempts'     => ['int', 11, 'null' => 0, 'default' => 0],
        ':keys' => [
            'PRIMARY' => 'id',
            'window'  => ['key_hash', 'key_type', 'scope', 'window_start', 'unique' => 1],
            // Non-unique: only used by the lazy sweep (authThrottleModel::deleteExpired()).
            'sweep'   => ['window_start'],
        ],
    ],
];
