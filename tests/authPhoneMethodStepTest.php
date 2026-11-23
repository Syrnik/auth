<?php
/**
 * @author Serge Rodovnichenko <serge@syrnik.com>
 * @copyright Serge Rodovnichenko, 2026
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * What the login form's OTP step needs from authPhoneMethod (AUTH-855):
 *
 *   - every step carries the number back, so phone.login_form.html can post
 *     it again on step 2 — authenticate() refuses without it;
 *   - "Send the code again" (otp_resend) resends even when a code was typed
 *     into the field, instead of verifying it and spending an attempt.
 *
 * Same fixture approach as authPhoneMethodThrottleTest: the send path runs
 * against a number nobody has (the throttle is hit before the lookup, so no
 * SMS gateway is reached), the verify path seeds the session state.
 */
class authPhoneMethodStepTest extends TestCase
{
    use authTestTemporaryTablesTrait;
    use authTestConfigOverrideTrait;

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
        wa()->getStorage()->del($this->sessionKey());
        $this->tearDownTemporaryTables();
        $this->restoreAuthConfig();
        parent::tearDown();
    }

    public function testWrongCodeStepCarriesTheNormalizedNumber(): void
    {
        $digits = $this->unknownNumber();
        $this->seedSession($digits);

        $vars = $this->stepVars(['phone' => '+' . $digits, 'otp_code' => '000000']);

        $this->assertTrue($vars['show_code_field']);
        $this->assertSame($digits, $vars['phone']);
        $this->assertStringContainsString('Неверный код', (string)$vars['error']);
    }

    public function testExpiredCodeStepCarriesTheNumber(): void
    {
        $digits = $this->unknownNumber();

        $vars = $this->stepVars(['phone' => $digits, 'otp_code' => '000000']);

        $this->assertSame($digits, $vars['phone']);
    }

    public function testThrottledResendStepCarriesTheNumber(): void
    {
        $digits = $this->unknownNumber();
        authThrottle::hit('otp_send', ['ip' => waRequest::getIp(), 'login' => $digits]);

        $vars = $this->stepVars(['phone' => $digits]);

        $this->assertSame($digits, $vars['phone']);
        $this->assertStringContainsString('Повторная отправка', (string)$vars['error']);
    }

    public function testResendIgnoresATypedCode(): void
    {
        $digits = $this->unknownNumber();
        $this->seedSession($digits);

        try {
            (new authPhoneMethod())->authenticate([
                'phone'      => $digits,
                'otp_code'   => '000000',
                'otp_resend' => '1',
            ]);
            $this->fail('expected authGuardException');
        } catch (authGuardException $e) {
            // The send path: nobody has this number.
        }

        // Took the send path, not the verify one: the send budget is spent,
        // and the typed code did not cost a guess.
        $this->assertSame(1, authThrottle::check('otp_send', ['login' => $digits])->attempts);
        $this->assertSame(0, wa()->getStorage()->get($this->sessionKey())['attempts']);
    }

    // -------------------------------------------------------------------------

    private function stepVars(array $params): array
    {
        $vars = null;
        try {
            (new authPhoneMethod())->authenticate($params);
        } catch (authMethodStepException $e) {
            $vars = $e->getTemplateVars();
        }
        $this->assertNotNull($vars, 'expected authMethodStepException');

        return $vars;
    }

    private function seedSession(string $digits): void
    {
        wa()->getStorage()->set($this->sessionKey(), [
            'contact_id' => 1,
            'phone'      => $digits,
            'hash'       => password_hash('123456', PASSWORD_DEFAULT),
            'expires'    => time() + 300,
            'attempts'   => 0,
        ]);
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
