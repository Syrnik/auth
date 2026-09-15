<?php

/**
 * Aggregate brute-force counters — one row per (key, key_type, scope, window),
 * not one row per attempt. See docs/adr/003-credential-throttle.md, decision 8.
 *
 * No policy here (attempt caps, windows, delay, lockout): those come from
 * authConfig and are applied by authThrottle. This model only knows how to
 * store and retrieve the aggregate.
 *
 * The window itself is a fixed bucket, not a sliding one: hit() writes to
 * window_start = floor(now / window_seconds) * window_seconds, which lets a
 * single atomic `INSERT ... ON DUPLICATE KEY UPDATE` on the (key_hash,
 * key_type, scope, window_start) unique key double as the concurrency
 * control — two simultaneous hits landing in the same bucket simply both
 * increment it, instead of racing a read-then-decide.
 */
class authThrottleModel extends waModel
{
    protected $table = 'auth_throttle';

    /**
     * The row for one key's current window, or null if it has not been hit yet.
     */
    public function getRow(string $scope, string $key_type, string $key_hash, string $window_start): ?array
    {
        return $this->query(
            "SELECT * FROM " . $this->table . "
             WHERE key_hash = s:key_hash AND key_type = s:key_type AND scope = s:scope AND window_start = s:window_start
             LIMIT 1",
            [
                'key_hash'     => $key_hash,
                'key_type'     => $key_type,
                'scope'        => $scope,
                'window_start' => $window_start,
            ]
        )->fetchAssoc() ?: null;
    }

    /**
     * Counts one attempt against a key's current window and returns the
     * row as it stands after the increment. $retention_seconds is how far
     * back deleteExpired() sweeps on this call — see its own docblock for why
     * that isn't every call.
     */
    public function hit(string $scope, string $key_type, string $key_hash, string $window_start, int $retention_seconds): array
    {
        $now = date('Y-m-d H:i:s');

        $this->exec(
            "INSERT INTO " . $this->table . " (key_hash, key_type, scope, window_start, last_attempt, attempts)
             VALUES (s:key_hash, s:key_type, s:scope, s:window_start, s:now, 1)
             ON DUPLICATE KEY UPDATE attempts = attempts + 1, last_attempt = s:now",
            [
                'key_hash'     => $key_hash,
                'key_type'     => $key_type,
                'scope'        => $scope,
                'window_start' => $window_start,
                'now'          => $now,
            ]
        );

        // Probabilistic, not on every hit(): a DELETE on the hot path (a failed
        // login) is write amplification and a lock-contention surface for a
        // sweep that has nothing to do with the request being served.
        if (random_int(1, 50) === 1) {
            $this->deleteExpired($retention_seconds);
        }

        return (array)$this->getRow($scope, $key_type, $key_hash, $window_start);
    }

    /**
     * Clears every window of one key — called on a successful login for the
     * identifier key only. Never call this for the IP key: see
     * authThrottle::reset()'s own docblock for why an IP counter must not be
     * resettable by a successful attempt.
     */
    public function reset(string $scope, string $key_type, string $key_hash): void
    {
        $this->exec(
            "DELETE FROM " . $this->table . " WHERE key_hash = s:key_hash AND key_type = s:key_type AND scope = s:scope",
            ['key_hash' => $key_hash, 'key_type' => $key_type, 'scope' => $scope]
        );
    }

    /**
     * Sweeps windows older than $older_than_seconds. Called from hit() at a
     * low, fixed probability rather than a cron: the table stays small
     * (aggregate rows, not a per-attempt log) and self-cleans without any
     * scheduled task, at the cost of a bounded amount of stale rows between
     * sweeps — the same trade-off authPasswordRecoveryModel makes.
     */
    public function deleteExpired(int $older_than_seconds): void
    {
        $this->exec(
            "DELETE FROM " . $this->table . " WHERE window_start < ?",
            date('Y-m-d H:i:s', time() - max(0, $older_than_seconds))
        );
    }
}
