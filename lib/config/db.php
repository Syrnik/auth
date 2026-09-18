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
    // One row per pending recovery request (email link or phone code) — see
    // authPasswordRecoveryModel and docs/adr/004-recovery-channels.md.
    'auth_password_recovery' => [
        'id'               => ['int', 11, 'null' => 0, 'autoincrement' => 1],
        // 0 for a decoy row (identifier did not resolve to a contact) — see
        // decision 5 of the ADR: a decoy must exist and behave identically to
        // a real request, so this stays NOT NULL rather than nullable.
        'contact_id'       => ['int', 11, 'null' => 0],
        // Recovery provider id (a recovery_channels entry) that issued this
        // row: 'email'/'phone' for the built-ins, or a plugin id
        // ('myplugin_plugin', 'oidc_plugin:gitlab') for a third-party
        // authRecoveryProvider — same id shape as login_methods, hence 64
        // chars, not 16. Decides which provider's verifyCode()/complete()
        // handles this row.
        'channel'          => ['varchar', 64, 'null' => 0, 'default' => 'email'],
        // sha256 of the provider's normalized identifier, never the raw
        // value — same reasoning as auth_throttle.key_hash: in the clear
        // this is someone's email or phone. The unique key below is on this,
        // not contact_id: every decoy shares contact_id = 0, and a key on
        // contact_id would let two decoys collide and swap each other's
        // attempt counters (decision 6 of the ADR).
        'identifier_hash'  => ['varchar', 64, 'null' => 0, 'default' => ''],
        'token'            => ['varchar', 64, 'null' => 0],
        // Non-empty only for a code-based provider (phone). A decoy row gets
        // a real hash of a real, never-sent code — an empty value here reads
        // as "the link alone proves it", which would route a decoy into the
        // wrong branch instead of the shared one (decision 5 of the ADR).
        'code_hash'        => ['varchar', 255, 'null' => 0, 'default' => ''],
        'attempts'         => ['int', 11, 'null' => 0, 'default' => 0],
        'created_datetime' => ['datetime', 'null' => 0],
        'expire_datetime'  => ['datetime', 'null' => 0],
        ':keys' => [
            'PRIMARY'    => 'id',
            'token'      => ['token', 'unique' => 1],
            // Replace-on-reissue and the decoy-collision guard both key off
            // this pair — see the identifier_hash comment above.
            'identifier' => ['identifier_hash', 'channel', 'unique' => 1],
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
