<?php
/**
 * @author Serge Rodovnichenko <serge@syrnik.com>
 * @copyright Serge Rodovnichenko, 2026
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * authPhoneMethod keys its otp_send throttle on the normalized number
 * (AUTH-572): '+7 900 123-45-67' and '79001234567' are one person and must
 * spend one budget — the same one authPhoneRecoveryProvider already keys on
 * authProfileValues::normalizePhone().
 *
 * Goes through the public authenticate(). The send path is exercised with a
 * number no contact has: sendOtp() hits the throttle before the lookup, so
 * the "not found" refusal comes after the counter is spent and no SMS
 * gateway is ever reached. The verify path seeds the session state sendOtp()
 * would have left, as authPhoneMethodConfirmTest does.
 */
class authPhoneMethodThrottleTest extends TestCase
{
    use authTestTemporaryTablesTrait;
    use authTestConfigOverrideTrait;
    use authTestContactFixtureTrait;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpTemporaryTables('auth_throttle');
        $this->overrideAuthConfig([
            'throttle_enabled'        => true,
            'throttle_login_attempts' => 5,
            'throttle_login_window'   => 900,
            'throttle_ip_attempts'    => 30,
            'throttle_ip_window'      => 900,
            'throttle_delay'          => 2,
            'throttle_otp_delay'      => 60,
            'throttle_lockout'        => 0,
            'throttle_store'          => null,
            'captcha_mode'            => 'off',
            'captcha_after_n'         => 3,
        ]);
    }

    protected function tearDown(): void
    {
        $this->tearDownTestContacts();
        wa()->getStorage()->del($this->sessionKey());
        $this->tearDownTemporaryTables();
        $this->restoreAuthConfig();
        parent::tearDown();
    }

    public function testSendIsCountedUnderTheNormalizedNumber(): void
    {
        $digits = $this->unknownNumber();

        $this->sendOtp($this->formatted($digits));

        $state = authThrottle::check('otp_send', ['login' => $digits]);
        $this->assertSame(1, $state->attempts);
    }

    public function testDifferentlyFormattedNumberSharesOneBudget(): void
    {
        $digits = $this->unknownNumber();

        $this->sendOtp($this->formatted($digits));

        // Retyped another way right away: the pacing delay of the first send
        // still applies, it is not a fresh counter.
        try {
            (new authPhoneMethod())->authenticate(['phone' => '+' . $digits]);
            $this->fail('expected authMethodStepException');
        } catch (authMethodStepException $e) {
            $this->assertStringContainsString('Повторная отправка', (string)($e->getTemplateVars()['error'] ?? ''));
        }
    }

    public function testCorrectCodeResetsTheNormalizedNumberCounter(): void
    {
        $digits  = '7900' . random_int(1000000, 9999999);
        $contact = $this->createTestContact(['phone' => $digits]);
        $code    = '123456';

        authThrottle::hit('otp_send', ['login' => $digits]);

        wa()->getStorage()->set($this->sessionKey(), [
            'contact_id' => $contact->getId(),
            'phone'      => $digits,
            'hash'       => password_hash($code, PASSWORD_DEFAULT),
            'expires'    => time() + 300,
            'attempts'   => 0,
        ]);

        $result = (new authPhoneMethod())->authenticate([
            'phone'    => $this->formatted($digits),
            'otp_code' => $code,
        ]);

        $this->assertSame($contact->getId(), $result);
        $this->assertSame(0, authThrottle::check('otp_send', ['login' => $digits])->attempts);
    }

    // -------------------------------------------------------------------------

    /** Sends a code to a number nobody has: spends the counter, then refuses. */
    private function sendOtp(string $phone): void
    {
        try {
            (new authPhoneMethod())->authenticate(['phone' => $phone]);
            $this->fail('expected authGuardException');
        } catch (authGuardException $e) {
            // expected — no contact has this number
        }
    }

    /** '79001234567' => '+7 (900) 123-45-67' */
    private function formatted(string $digits): string
    {
        return sprintf(
            '+%s (%s) %s-%s-%s',
            substr($digits, 0, 1),
            substr($digits, 1, 3),
            substr($digits, 4, 3),
            substr($digits, 7, 2),
            substr($digits, 9, 2)
        );
    }

    private function unknownNumber(): string
    {
        do {
            $digits = '7900' . random_int(1000000, 9999999);
        } while ((new waContactDataModel())->getByField(['field' => 'phone', 'value' => $digits]));

        return $digits;
    }

    private function sessionKey(): string
    {
        return (new ReflectionClass(authPhoneMethod::class))->getConstant('OTP_SESSION_KEY');
    }
}
