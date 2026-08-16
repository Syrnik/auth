<?php
/**
 * @author Serge Rodovnichenko <serge@syrnik.com>
 * @copyright Serge Rodovnichenko, 2026
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * authHelper-методы, целиком определяемые login_methods домена
 *
 * Использует authTestConfigOverrideTrait, чтобы прогнать authPluginManager::getEnabled()
 * (а через него — всё, что от него зависит) по контролируемому набору login_methods,
 * а не по тому, что сейчас включено на syrnik.local.
 */
class authHelperLoginMethodsTest extends TestCase
{
    use authTestConfigOverrideTrait;

    protected function tearDown(): void
    {
        $this->restoreAuthConfig();
        parent::tearDown();
    }

    public function testGetLoginFieldsOrderIsEmailThenPhone(): void
    {
        // config.php declares the check order ['email', 'phone'] explicitly (authHelper::
        // getLoginFields) — login_methods below lists phone first to prove the result order
        // does not just mirror the input order.
        $this->overrideAuthConfig(['login_methods' => ['phone', 'email']]);

        $this->assertSame(['email', 'phone'], authHelper::getLoginFields());
    }

    public function testLoginFieldRequiresABuiltinMethod(): void
    {
        // A plugin id happening to equal 'email' (hypothetically) must not count as the
        // email login field — isLoginField() checks instanceof authBuiltinMethod, not
        // just the string.
        $this->overrideAuthConfig(['login_methods' => ['phone']]);

        $this->assertTrue(authHelper::isLoginField('phone'));
        $this->assertFalse(authHelper::isLoginField('email'));
    }

    public function testPhoneFieldIsPasswordlessEmailIsNot(): void
    {
        $this->overrideAuthConfig(['login_methods' => ['email', 'phone']]);

        $this->assertFalse(authHelper::isPasswordlessLoginField('email'));
        $this->assertTrue(authHelper::isPasswordlessLoginField('phone'));
    }

    public function testHasPasswordLoginRequiresAPasswordMethod(): void
    {
        $this->overrideAuthConfig(['login_methods' => ['phone']]);
        $this->assertFalse(authHelper::hasPasswordLogin());

        $this->overrideAuthConfig(['login_methods' => ['phone', 'email']]);
        $this->assertTrue(authHelper::hasPasswordLogin());
    }

    public function testRegistrationDisabledWhenOnlyOAuthMethodsEnabled(): void
    {
        // A domain with only OAuth methods creates contacts on first login via
        // authContactResolver — a separate signup form would be redundant, per
        // authHelper::isRegistrationEnabled()'s own docblock.
        $this->overrideAuthConfig([
            'login_methods' => ['waid'],
            'signup_enabled' => true,
        ]);

        $this->assertFalse(authHelper::isRegistrationEnabled());
    }

    public function testRegistrationEnabledWhenAPasswordMethodIsPresentToo(): void
    {
        $this->overrideAuthConfig([
            'login_methods' => ['waid', 'email'],
            'signup_enabled' => true,
        ]);

        $this->assertTrue(authHelper::isRegistrationEnabled());
    }

    public function testRegistrationDisabledWhenSignupDisabledEvenWithFormMethod(): void
    {
        $this->overrideAuthConfig([
            'login_methods'  => ['email'],
            'signup_enabled' => false,
        ]);

        $this->assertFalse(authHelper::isRegistrationEnabled());
    }

    public function testHasRecoveryRequiresRecoveryEnabledAndACapableMethod(): void
    {
        // email (authEmailMethod::HAS_RECOVERY = true) qualifies, phone (OTP) does not.
        $this->overrideAuthConfig([
            'login_methods'    => ['phone'],
            'recovery_enabled' => true,
        ]);
        $this->assertFalse(authHelper::hasRecovery());

        $this->overrideAuthConfig([
            'login_methods'    => ['email'],
            'recovery_enabled' => true,
        ]);
        $this->assertTrue(authHelper::hasRecovery());

        $this->overrideAuthConfig([
            'login_methods'    => ['email'],
            'recovery_enabled' => false,
        ]);
        $this->assertFalse(authHelper::hasRecovery());
    }
}
