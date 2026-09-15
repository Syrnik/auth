<?php
/**
 * @author Serge Rodovnichenko <serge@syrnik.com>
 * @copyright Serge Rodovnichenko, 2026
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * The split authLoginController's throttle relies on (AUTH-49, decision 1 of
 * docs/adr/003-credential-throttle.md): authEmailMethod/authLoginMethod must
 * throw authCredentialFailureException for a real mismatch and the plain
 * parent authGuardException for everything else, or the controller's
 * `catch (authCredentialFailureException)` — which counts a throttle hit —
 * either counts a submission that was never an attempt, or silently stops
 * counting real ones because the more specific catch never fires.
 *
 * Only the "no such user" branch is covered against a real (mismatched)
 * lookup, not "wrong password for an existing user": that would need a real
 * throwaway contact in the shared wa_contact table, which isn't an app-owned
 * table authTestTemporaryTablesTrait can shadow — the two branches throw the
 * identical exception from the identical line for both cases in both
 * methods, so the untested branch carries no separate risk.
 */
class authCredentialFailureExceptionTest extends TestCase
{
    use authTestConfigOverrideTrait;

    protected function setUp(): void
    {
        parent::setUp();
        // authPhoneMethod::sendOtp() below goes through authThrottle before
        // reaching its own authGuardException — disabling the throttle keeps
        // this test from writing a real row into the dev database's
        // auth_throttle for a scope this test suite doesn't otherwise touch.
        $this->overrideAuthConfig(['throttle_enabled' => false]);
    }

    protected function tearDown(): void
    {
        $this->restoreAuthConfig();
        parent::tearDown();
    }

    public function testIsASubclassOfAuthGuardException(): void
    {
        $this->assertInstanceOf(authGuardException::class, new authCredentialFailureException('x'));
    }

    public function testEmailMethodEmptyFormIsAGuardExceptionButNotACredentialFailure(): void
    {
        $method = new authEmailMethod();

        try {
            $method->authenticate(['email' => '', 'password' => '']);
            $this->fail('Expected authGuardException');
        } catch (authCredentialFailureException $e) {
            $this->fail('An empty form is not an attempt — must not throw the counted subclass');
        } catch (authGuardException $e) {
            $this->assertNotInstanceOf(authCredentialFailureException::class, $e);
        }
    }

    public function testEmailMethodUnknownEmailIsACredentialFailure(): void
    {
        $method = new authEmailMethod();

        $this->expectException(authCredentialFailureException::class);
        $method->authenticate([
            'email'    => 'no-such-contact-auth-49-test@example.invalid',
            'password' => 'whatever',
        ]);
    }

    public function testLoginMethodEmptyFormIsAGuardExceptionButNotACredentialFailure(): void
    {
        $method = new authLoginMethod();

        try {
            $method->authenticate(['login' => '', 'password' => '']);
            $this->fail('Expected authGuardException');
        } catch (authCredentialFailureException $e) {
            $this->fail('An empty form is not an attempt — must not throw the counted subclass');
        } catch (authGuardException $e) {
            $this->assertNotInstanceOf(authCredentialFailureException::class, $e);
        }
    }

    public function testLoginMethodUnknownLoginIsACredentialFailure(): void
    {
        $method = new authLoginMethod();

        $this->expectException(authCredentialFailureException::class);
        $method->authenticate([
            'login'    => 'no-such-contact-auth-49-test',
            'password' => 'whatever',
        ]);
    }

    /**
     * authPhoneMethod's own limits (unknown number, OTP resend throttle) stay
     * authGuardException — they are a different scope ('otp_send'), never
     * 'login', so the controller must not learn to treat them as a
     * credential failure the way it does for email/login.
     */
    public function testPhoneMethodUnknownNumberIsNotACredentialFailure(): void
    {
        $method = new authPhoneMethod();

        try {
            $method->authenticate(['phone' => '+70000000000']);
            $this->fail('Expected authGuardException');
        } catch (authCredentialFailureException $e) {
            $this->fail('authPhoneMethod has no credential-failure branch — it must stay a plain authGuardException');
        } catch (authGuardException $e) {
            $this->assertNotInstanceOf(authCredentialFailureException::class, $e);
        }
    }
}
