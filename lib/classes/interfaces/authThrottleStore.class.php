<?php

/**
 * Where throttle counters are aggregated. This is the one piece of the
 * brute-force throttle (AUTH-49) that is pluggable — see decision 2 of
 * docs/adr/003-credential-throttle.md: policy (attempt caps, windows, delay,
 * lockout, captcha threshold) is a fixed set of domain settings and is never
 * plugged; the counter store is, because a multi-app-server install needs a
 * shared backend (Redis, memcached) instead of the built-in MySQL table.
 *
 * A store knows nothing about scopes' meaning, thresholds, or messages — it
 * is exactly as narrow as authThrottleModel itself, whose method shapes it
 * mirrors, so the built-in authThrottleDbStore is a thin pass-through.
 */
interface authThrottleStore
{
    /**
     * The row for one key's current window — ['attempts' => int,
     * 'window_start' => 'Y-m-d H:i:s', 'last_attempt' => 'Y-m-d H:i:s'] — or
     * null if that window has not been hit yet.
     */
    public function getRow(string $scope, string $key_type, string $key_hash, string $window_start): ?array;

    /**
     * Counts one attempt against a key's current window and returns the row
     * as it stands after the increment, in the same shape as getRow().
     */
    public function hit(string $scope, string $key_type, string $key_hash, string $window_start): array;

    /**
     * Clears every window of one key.
     */
    public function reset(string $scope, string $key_type, string $key_hash): void;
}
