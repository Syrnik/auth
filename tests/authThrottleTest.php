<?php
/**
 * @author Serge Rodovnichenko <serge@syrnik.com>
 * @copyright Serge Rodovnichenko, 2026
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * authThrottle — the brute-force throttle service (AUTH-49).
 *
 * Two kinds of coverage here: pure arithmetic (windowStart(), evaluateKeyState())
 * needs no database and is exercised through @dataProvider the way
 * authPluginManagerTest covers splitInstance(); everything else goes through the
 * real check()/hit()/reset() against auth_throttle, shadowed with
 * authTestTemporaryTablesTrait, with thresholds substituted via
 * authTestConfigOverrideTrait so the test never depends on syrnik.local's actual
 * settings.
 */
class authThrottleTest extends TestCase
{
    use authTestTemporaryTablesTrait;
    use authTestConfigOverrideTrait;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpTemporaryTables('auth_throttle');
    }

    protected function tearDown(): void
    {
        $this->tearDownTemporaryTables();
        $this->restoreAuthConfig();
        parent::tearDown();
    }

    // -------------------------------------------------------------------------
    // Pure arithmetic
    // -------------------------------------------------------------------------

    public function testWindowStartBucketsAreFixedSize(): void
    {
        // 12:07:33 and 12:14:59 both fall in the [12:00:00, 12:15:00) bucket
        // of a 900-second window; 12:15:00 itself starts the next one.
        $a = strtotime('2026-01-01 12:07:33');
        $b = strtotime('2026-01-01 12:14:59');
        $c = strtotime('2026-01-01 12:15:00');

        $this->assertSame('2026-01-01 12:00:00', authThrottle::windowStart($a, 900));
        $this->assertSame('2026-01-01 12:00:00', authThrottle::windowStart($b, 900));
        $this->assertSame('2026-01-01 12:15:00', authThrottle::windowStart($c, 900));
    }

    /**
     * @dataProvider evaluateKeyStateProvider
     */
    public function testEvaluateKeyState(
        ?array $row,
        int $now,
        int $max_attempts,
        int $delay_seconds,
        int $lockout_seconds,
        bool $lockout_allowed,
        array $expected
    ): void {
        $state = authThrottle::evaluateKeyState($row, $now, $max_attempts, $delay_seconds, $lockout_seconds, $lockout_allowed);
        $this->assertSame($expected, $state);
    }

    public function evaluateKeyStateProvider(): array
    {
        // evaluateKeyState() reads last_attempt with strtotime(), which
        // resolves a bare date string against the framework's configured
        // timezone (Europe/Moscow, wa-config/SystemConfig.class.php) — so
        // $now and last_attempt are built from the same anchor via date()
        // instead of hand-typed literals, or the two would silently drift
        // by the zone's UTC offset.
        $anchor = 1700000000;
        $now    = $anchor + 1000;
        $at     = static fn(int $seconds_ago) => date('Y-m-d H:i:s', $now - $seconds_ago);

        return [
            'no row at all: nothing to wait for' => [
                null, $now, 5, 2, 0, true,
                ['attempts' => 0, 'retry_after' => 0, 'over_threshold' => false],
            ],
            'first attempt: delay grows from it immediately' => [
                ['attempts' => 1, 'last_attempt' => $at(0)],
                $now, 5, 2, 0, true,
                ['attempts' => 1, 'retry_after' => 2, 'over_threshold' => false],
            ],
            'delay window already elapsed: no wait left' => [
                ['attempts' => 1, 'last_attempt' => $at(5)], // needed 2s, 5s have passed
                $now, 5, 2, 0, true,
                ['attempts' => 1, 'retry_after' => 0, 'over_threshold' => false],
            ],
            'at threshold, lockout disallowed for this key type: delay only' => [
                ['attempts' => 5, 'last_attempt' => $at(0)],
                $now, 5, 2, 100, false,
                ['attempts' => 5, 'retry_after' => 10, 'over_threshold' => true],
            ],
            'at threshold, lockout allowed: the longer of delay and lockout wins' => [
                ['attempts' => 5, 'last_attempt' => $at(0)],
                $now, 5, 2, 100, true,
                ['attempts' => 5, 'retry_after' => 100, 'over_threshold' => true],
            ],
            'below threshold: lockout never considered even if allowed' => [
                ['attempts' => 4, 'last_attempt' => $at(0)],
                $now, 5, 2, 100, true,
                ['attempts' => 4, 'retry_after' => 8, 'over_threshold' => false],
            ],
        ];
    }

    // -------------------------------------------------------------------------
    // Service integration
    // -------------------------------------------------------------------------

    public function testTwoCountersAreIndependent(): void
    {
        $this->overrideAuthConfig($this->config(['throttle_ip_attempts' => 3]));

        for ($i = 0; $i < 3; $i++) {
            authThrottle::hit('login', ['ip' => '203.0.113.1']);
        }

        // The identifier was never hit — it must read as untouched even
        // though the IP sharing this request has a maxed-out counter.
        $state = authThrottle::check('login', ['login' => 'victim@example.test']);
        $this->assertSame(0, $state->attempts);
        $this->assertFalse($state->blocked);
    }

    public function testResetClearsTheIdentifierButNeverTheIpCounter(): void
    {
        $this->overrideAuthConfig($this->config());

        authThrottle::hit('login', ['ip' => '203.0.113.5', 'login' => 'user@example.test']);
        authThrottle::hit('login', ['ip' => '203.0.113.5', 'login' => 'user@example.test']);

        // Only the identifier key is ever passed to reset() — see
        // authThrottle's own docblock on why 'ip' must never be.
        authThrottle::reset('login', ['login' => 'user@example.test']);

        $identifier_state = authThrottle::check('login', ['login' => 'user@example.test']);
        $this->assertSame(0, $identifier_state->attempts);

        $ip_state = authThrottle::check('login', ['ip' => '203.0.113.5']);
        $this->assertSame(2, $ip_state->attempts);
    }

    public function testLockoutAppliesOnlyToTheIpKey(): void
    {
        $this->overrideAuthConfig($this->config([
            'throttle_login_attempts' => 2,
            'throttle_ip_attempts'    => 2,
            'throttle_delay'          => 0,
            'throttle_lockout'        => 100,
        ]));

        for ($i = 0; $i < 2; $i++) {
            authThrottle::hit('login', ['ip' => '203.0.113.9']);
            authThrottle::hit('login', ['login' => 'someone@example.test']);
        }

        $ip_state = authThrottle::check('login', ['ip' => '203.0.113.9']);
        $this->assertTrue($ip_state->blocked);

        // Same attempt count, same lockout setting — the identifier is
        // simply exempt from it (decision 4 of docs/adr/003-credential-throttle.md).
        $identifier_state = authThrottle::check('login', ['login' => 'someone@example.test']);
        $this->assertFalse($identifier_state->blocked);
    }

    public function testCaptchaRequiredEscalatesAfterNAttempts(): void
    {
        $this->overrideAuthConfig($this->config([
            'captcha_mode'    => 'after_n',
            'captcha_after_n' => 2,
            'throttle_delay'  => 0,
        ]));

        $keys = ['login' => 'someone@example.test'];

        $after_first = authThrottle::hit('login', $keys);
        $this->assertFalse($after_first->captchaRequired);

        $after_second = authThrottle::hit('login', $keys);
        $this->assertTrue($after_second->captchaRequired);
    }

    public function testCaptchaModeOffNeverRequiresIt(): void
    {
        $this->overrideAuthConfig($this->config(['captcha_mode' => 'off', 'throttle_login_attempts' => 1]));

        $state = authThrottle::hit('login', ['login' => 'someone@example.test']);
        $this->assertFalse($state->captchaRequired);
    }

    public function testCaptchaDecisionOnlyAppliesToTheLoginScope(): void
    {
        $this->overrideAuthConfig($this->config(['captcha_mode' => 'always']));

        // 'signup'/'recovery'/'otp_send' never carry a captcha_required
        // opinion from the throttle — registration's own unconditional
        // widget is a separate call (authHelper::getCaptchaWidget(true)),
        // not something authThrottle decides.
        $state = authThrottle::hit('signup', ['ip' => '203.0.113.20']);
        $this->assertFalse($state->captchaRequired);
    }

    public function testDisabledThrottleIsATrueNoOp(): void
    {
        $this->overrideAuthConfig($this->config(['throttle_enabled' => false]));

        for ($i = 0; $i < 10; $i++) {
            authThrottle::hit('login', ['ip' => '203.0.113.30', 'login' => 'someone@example.test']);
        }

        $state = authThrottle::check('login', ['ip' => '203.0.113.30', 'login' => 'someone@example.test']);
        $this->assertFalse($state->blocked);
        $this->assertSame(0, $state->attempts);

        // Not just "reads as clear" — no row was ever written, so
        // re-enabling the throttle later doesn't inherit a stale count.
        $model = new authThrottleModel();
        $window = authThrottle::windowStart(time(), 900);
        $this->assertNull($model->getRow('login', 'ip', hash('sha256', '203.0.113.30'), $window));
    }

    public function testResetOnOtpSendClearsThePhoneButNeverTheIpCounter(): void
    {
        // Mirrors testResetClearsTheIdentifierButNeverTheIpCounter for the
        // otp_send scope: authPhoneMethod::verifyOtp() resets the phone on a
        // successful code entry, exactly the same contract as a successful
        // login resetting scope 'login' — the IP is left alone regardless.
        $this->overrideAuthConfig($this->config());

        authThrottle::hit('otp_send', ['ip' => '203.0.113.40', 'login' => '+70000000000']);
        authThrottle::hit('otp_send', ['ip' => '203.0.113.40', 'login' => '+70000000000']);

        authThrottle::reset('otp_send', ['login' => '+70000000000']);

        $phone_state = authThrottle::check('otp_send', ['login' => '+70000000000']);
        $this->assertSame(0, $phone_state->attempts);

        $ip_state = authThrottle::check('otp_send', ['ip' => '203.0.113.40']);
        $this->assertSame(2, $ip_state->attempts);
    }

    public function testOtpSendScopeUsesItsOwnDelayNotThePasswordOne(): void
    {
        // otp_send paces a paid SMS resend, not a password guess — sharing
        // throttle_delay (tuned in single-digit seconds) would let a resend
        // through almost immediately; throttle_otp_delay is the knob that
        // replaced the old hardcoded RESEND_COOLDOWN_SECONDS = 60.
        $this->overrideAuthConfig($this->config(['throttle_otp_delay' => 45, 'throttle_delay' => 1]));

        $state = authThrottle::hit('otp_send', ['login' => '+70000000000']);
        $this->assertSame(45, $state->retryAfter);
    }

    public function testIdentifierIsCaseAndWhitespaceNormalizedButIpIsNot(): void
    {
        $this->overrideAuthConfig($this->config());

        authThrottle::hit('login', ['login' => '  User@Example.TEST  ']);
        $state = authThrottle::check('login', ['login' => 'user@example.test']);

        $this->assertSame(1, $state->attempts);
    }

    // -------------------------------------------------------------------------

    /**
     * A complete, fast domain config for these tests — small enough windows
     * and thresholds that a handful of hit() calls exercise escalation
     * without depending on real time passing. $overrides replaces individual
     * keys.
     */
    private function config(array $overrides = []): array
    {
        return array_merge([
            'throttle_enabled'        => true,
            'throttle_login_attempts' => 5,
            'throttle_login_window'   => 900,
            'throttle_ip_attempts'    => 30,
            'throttle_ip_window'      => 900,
            'throttle_delay'          => 2,
            'throttle_otp_delay'      => 60,
            'throttle_lockout'        => 0,
            'throttle_store'          => null,
            'captcha_mode'            => 'always',
            'captcha_after_n'         => 3,
        ], $overrides);
    }
}
