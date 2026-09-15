<?php

/**
 * Brute-force throttle (AUTH-49) — a core service, not a guard plugin, called
 * before a request resolves to a contact. See docs/adr/003-credential-throttle.md
 * for why: authGuard runs after authenticate() already succeeded, which is
 * structurally too late to see a failed attempt.
 *
 * Two independent counters, never a combined (ip, login) key: 'ip' from
 * waRequest::getIp() and 'login' — the identifier exactly as typed, before it
 * resolves to a contact (an unresolved identifier has no contact_id to key on,
 * and hashing it is what keeps the stored value from being the visitor's
 * plain-text email). A window is a fixed bucket
 * (windowStart()), not a sliding one — see authThrottleModel's own docblock.
 *
 * Two contracts a caller must not break:
 *
 * - reset() must only ever be called with the identifier key ('login'),
 *   never 'ip'. Resetting the IP counter on a successful login would defeat
 *   the reason it exists: credential stuffing succeeds on some fraction of
 *   attempts by construction, and if every success cleared the IP window, the
 *   IP threshold would never fire against exactly that attack. A successful
 *   login only proves the attempts against *that identifier* weren't an
 *   attack; the IP window is left to expire on its own.
 * - throttle_lockout (a flat refusal for its own duration once a key's
 *   attempts reach its cap) only ever applies to the 'ip' key. For 'login' it
 *   is not read at all, regardless of its value — a hard block keyed on the
 *   identifier is a lever the attacker controls (decision 4 of the ADR): they
 *   need only know the victim's email to lock them out, without ever
 *   guessing the password. The identifier gets the growing per-attempt delay
 *   (below) and nothing harder.
 *
 * What "blocked" means here is always a refusal *for now*, expressed as
 * retryAfter seconds — never a sleep() in the request. Sleeping in a PHP-FPM
 * worker to slow an attacker down is a self-inflicted DoS: the request still
 * pins a worker and the session lock for the sleep's duration, so the
 * cheapest way to exhaust the pool becomes sending throttled requests, not
 * guessing passwords.
 */
class authThrottle
{
    /**
     * Read-only: does this request's key(s) currently have to wait, and
     * should a captcha be shown? $keys is [key_type => raw value], e.g.
     * ['ip' => waRequest::getIp(), 'login' => $typed_identifier] — omit a key
     * entirely for a scope that doesn't count it (registration/recovery
     * count only 'ip', see docs/adr/003-credential-throttle.md's coverage
     * table).
     */
    public static function check(string $scope, array $keys): authThrottleState
    {
        return self::evaluate($scope, $keys, false);
    }

    /**
     * Counts one attempt against every key given and returns the resulting
     * state. Call on a request that should count — a failed credential check,
     * an accepted signup/recovery/OTP-send POST — never on a request that was
     * never attempted at all (an empty form submit is not a hit).
     */
    public static function hit(string $scope, array $keys): authThrottleState
    {
        return self::evaluate($scope, $keys, true);
    }

    /**
     * Clears every window of the given keys. See this class's own docblock:
     * never call this with an 'ip' key.
     */
    public static function reset(string $scope, array $keys): void
    {
        if (!self::enabled()) {
            return;
        }

        $store = self::store();
        foreach ($keys as $key_type => $raw) {
            $raw = trim((string)$raw);
            if ($raw === '') {
                continue;
            }
            $store->reset($scope, $key_type, self::hashKey(self::normalize($key_type, $raw)));
        }
    }

    /**
     * The identifier as the visitor typed it — before it resolves to (or
     * fails to resolve to) a contact — from a login POST body. First
     * non-empty of email/login/phone; the three built-in methods never
     * submit more than one of these on a single request.
     */
    public static function identifierFromPost(array $post): string
    {
        foreach (['email', 'login', 'phone'] as $field) {
            $value = trim((string)($post[$field] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }
        return '';
    }

    public static function blockedMessage(int $retry_after): string
    {
        return sprintf(_w('Too many attempts. Try again in %d seconds.'), max(1, $retry_after));
    }

    // -------------------------------------------------------------------------
    // Pure arithmetic — no wa() calls, so these are covered by @dataProvider
    // tests directly, the way authBlackmailguardPlugin::matchesMask() is.
    // -------------------------------------------------------------------------

    /**
     * The fixed-size bucket $timestamp falls into for a $window_seconds-long
     * window. Shared math: authThrottle computes it before calling the store,
     * and the store's own hit()/getRow() key off the exact value handed to
     * them — this is what makes two concurrent hits in the same bucket
     * collapse into one atomic increment instead of racing a read-then-decide.
     */
    public static function windowStart(int $timestamp, int $window_seconds): string
    {
        $window_seconds = max(1, $window_seconds);
        $bucket = intdiv($timestamp, $window_seconds) * $window_seconds;
        return date('Y-m-d H:i:s', $bucket);
    }

    /**
     * The refusal window and attempt count for one key, given its current
     * window row (or null). The window length itself plays no part here —
     * getRow()/hit() already scoped $row to the current bucket by exact
     * window_start match, so a row reaching this function is by definition
     * "in the current window" or absent entirely. $lockout_allowed gates
     * whether $lockout_seconds is honored at all — false for the identifier
     * key, always, regardless of the value passed in (see this class's own
     * docblock).
     *
     * @param array{attempts?:int,last_attempt?:string}|null $row
     * @return array{attempts:int,retry_after:int,over_threshold:bool}
     */
    public static function evaluateKeyState(
        ?array $row,
        int $now,
        int $max_attempts,
        int $delay_seconds,
        int $lockout_seconds,
        bool $lockout_allowed
    ): array {
        $attempts = (int)($row['attempts'] ?? 0);
        if ($attempts === 0) {
            return ['attempts' => 0, 'retry_after' => 0, 'over_threshold' => false];
        }

        $last_attempt = isset($row['last_attempt']) ? (int)strtotime((string)$row['last_attempt']) : $now;
        $elapsed      = max(0, $now - $last_attempt);

        // Grows with every attempt in the window: attempt 1 waits
        // throttle_delay seconds before attempt 2 is accepted, attempt 2
        // waits twice that before attempt 3, and so on.
        $delay_wait = max(0, $attempts * $delay_seconds - $elapsed);

        $over_threshold = $attempts >= $max_attempts;

        $lockout_wait = 0;
        if ($lockout_allowed && $lockout_seconds > 0 && $over_threshold) {
            $lockout_wait = max(0, $lockout_seconds - $elapsed);
        }

        return [
            'attempts'       => $attempts,
            'retry_after'    => max($delay_wait, $lockout_wait),
            'over_threshold' => $over_threshold,
        ];
    }

    // -------------------------------------------------------------------------

    private static function evaluate(string $scope, array $keys, bool $increment): authThrottleState
    {
        if (!self::enabled()) {
            return new authThrottleState();
        }

        $store           = self::store();
        $now             = time();
        // otp_send gets its own, much larger step: it paces resends of a
        // paid SMS, not guesses against a password (see throttle_otp_delay
        // in lib/config/config.php).
        $delay_seconds   = $scope === 'otp_send' ? authConfig::getThrottleOtpDelay() : authConfig::getThrottleDelay();
        $lockout_seconds = authConfig::getThrottleLockout();

        $retry_after      = 0;
        $attempts         = 0;
        $captcha_attempts = 0;

        foreach ($keys as $key_type => $raw) {
            $raw = trim((string)$raw);
            if ($raw === '') {
                continue;
            }

            [$window_seconds, $max_attempts, $lockout_allowed] = self::policyFor($key_type);
            $key_hash     = self::hashKey(self::normalize($key_type, $raw));
            $window_start = self::windowStart($now, $window_seconds);

            $row = $increment
                ? $store->hit($scope, $key_type, $key_hash, $window_start)
                : $store->getRow($scope, $key_type, $key_hash, $window_start);

            $state = self::evaluateKeyState(
                $row,
                $now,
                $max_attempts,
                $delay_seconds,
                $lockout_seconds,
                $lockout_allowed
            );

            $retry_after      = max($retry_after, $state['retry_after']);
            $attempts         = max($attempts, $state['attempts']);
            $captcha_attempts = max($captcha_attempts, $state['attempts']);

            // Fired exactly on the hit that crosses the threshold, not on
            // every one after it, and never from a read-only check() — an
            // external reactor (fail2ban, a WAF, a SIEM) wants the moment a
            // key tripped, not a running commentary. See docs/adr/003-
            // credential-throttle.md, decision 2.
            if ($increment && $state['attempts'] === $max_attempts) {
                // wa()->event()'s $params is by-reference — an inline array
                // literal isn't a valid argument for that.
                $event_params = [
                    'scope'     => $scope,
                    'key_type'  => $key_type,
                    'ip'        => waRequest::getIp(),
                    'threshold' => $max_attempts,
                ];
                wa()->event('throttle_blocked', $event_params);
            }
        }

        $captcha_required = $scope === 'login' && self::captchaRequired($captcha_attempts);

        return new authThrottleState($retry_after > 0, $retry_after, $captcha_required, $attempts);
    }

    private static function captchaRequired(int $attempts): bool
    {
        $mode = authConfig::getCaptchaMode();
        if ($mode === 'off') {
            return false;
        }
        if ($mode === 'always') {
            return true;
        }
        $n = authConfig::getCaptchaAfterN();
        return $n > 0 && $attempts >= $n;
    }

    /**
     * @return array{0:int,1:int,2:bool} [window_seconds, max_attempts, lockout_allowed]
     */
    private static function policyFor(string $key_type): array
    {
        [$max_attempts, $window_seconds] = authConfig::getThrottlePolicy($key_type);
        return [$window_seconds, $max_attempts, $key_type === 'ip'];
    }

    private static function normalize(string $key_type, string $raw): string
    {
        return $key_type === 'ip' ? $raw : mb_strtolower($raw);
    }

    private static function hashKey(string $normalized): string
    {
        return hash('sha256', $normalized);
    }

    private static function enabled(): bool
    {
        return authConfig::isThrottleEnabled();
    }

    private static function store(): authThrottleStore
    {
        return authPluginManager::getThrottleStore();
    }
}
