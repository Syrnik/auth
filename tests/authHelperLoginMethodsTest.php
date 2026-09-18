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

    /**
     * hasRecovery() is a domain-level gate now, not a property of whichever
     * method signs someone in (docs/adr/004-recovery-channels.md, decision
     * 1): hasPasswordLogin() (something to reset) AND at least one enabled
     * authRecoveryProvider (something to deliver a proof through).
     */
    public function testHasRecoveryRequiresRecoveryEnabledAPasswordMethodAndAChannel(): void
    {
        // phone (OTP) is passwordless — nothing to reset, regardless of
        // recovery_enabled or which channels are configured.
        $this->overrideAuthConfig([
            'login_methods'    => ['phone'],
            'recovery_enabled' => true,
        ]);
        $this->assertFalse(authHelper::hasRecovery());

        // email is both a password method and — via the distribution
        // default, untouched here — an enabled recovery channel.
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

        // The case this rewrite exists to prove: a domain with ONLY
        // authLoginMethod (login+password) used to get HAS_RECOVERY = false
        // and a permanent 404 on recovery/ — it now gets recovery like any
        // other password method, because recovery no longer asks the method
        // at all.
        $this->overrideAuthConfig([
            'login_methods'    => ['login'],
            'recovery_enabled' => true,
        ]);
        $this->assertTrue(authHelper::hasRecovery());

        // A password method with no enabled recovery channel still gets
        // nothing — there is a password to reset but no way to deliver a proof.
        $this->overrideAuthConfig([
            'login_methods'     => ['email'],
            'recovery_enabled'  => true,
            'recovery_channels' => [],
        ]);
        $this->assertFalse(authHelper::hasRecovery());
    }
}
