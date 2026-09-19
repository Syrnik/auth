<?php
/**
 * @author Serge Rodovnichenko <serge@syrnik.com>
 * @copyright Serge Rodovnichenko, 2026
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * authLoginConfirmGate — the strict-login gate, decision 3 of
 * docs/adr/005-value-confirmation.md. Three properties checked here, each
 * one a documented constraint from that decision:
 *
 *   - fieldFor() exempts every method except authEmailMethod — a phone OTP
 *     login's own success IS the proof, and authLoginMethod has no
 *     confirmation storage to check at all;
 *   - isEnabled() also requires signup_confirm and 'email' in signup_fields,
 *     not login_require_confirmed alone — an account created while
 *     signup_confirm was off never had anything to prove;
 *   - check()'s refusal is authGuardException but never
 *     authCredentialFailureException, so a legitimate password does not
 *     spend the login throttle's budget (docs/adr/003-credential-throttle.md).
 */
class authLoginConfirmGateTest extends TestCase
{
    use authTestContactFixtureTrait;
    use authTestConfigOverrideTrait;

    protected function tearDown(): void
    {
        $this->restoreAuthConfig();
        $this->tearDownTestContacts();
        parent::tearDown();
    }

    public function testFieldForIsEmailOnlyForAuthEmailMethod(): void
    {
        $this->assertSame('email', authLoginConfirmGate::fieldFor(new authEmailMethod()));
        $this->assertNull(authLoginConfirmGate::fieldFor(new authPhoneMethod()));
        $this->assertNull(authLoginConfirmGate::fieldFor(new authLoginMethod()));
        $this->assertNull(authLoginConfirmGate::fieldFor(new authWaidMethod()));
    }

    public function testIsEnabledFalseWhenTheFlagItselfIsOff(): void
    {
        $this->overrideAuthConfig([
            'login_require_confirmed' => false,
            'signup_confirm'          => true,
            'signup_fields'           => ['email'],
        ]);

        $this->assertFalse(authLoginConfirmGate::isEnabled());
    }

    public function testIsEnabledFalseWhenSignupConfirmIsOff(): void
    {
        $this->overrideAuthConfig([
            'login_require_confirmed' => true,
            'signup_confirm'          => false,
            'signup_fields'           => ['email'],
        ]);

        // An account created while signup_confirm was off never had its
        // email asked for proof — the gate must not brick it on its very
        // next login.
        $this->assertFalse(authLoginConfirmGate::isEnabled());
    }

    public function testIsEnabledFalseWhenEmailIsNotInSignupFields(): void
    {
        $this->overrideAuthConfig([
            'login_require_confirmed' => true,
            'signup_confirm'          => true,
            'signup_fields'           => ['firstname', 'phone'],
        ]);

        $this->assertFalse(authLoginConfirmGate::isEnabled());
    }

    public function testIsEnabledTrueWhenAllThreeConditionsHold(): void
    {
        $this->overrideAuthConfig([
            'login_require_confirmed' => true,
            'signup_confirm'          => true,
            'signup_fields'           => ['firstname', 'email', 'password'],
        ]);

        $this->assertTrue(authLoginConfirmGate::isEnabled());
    }

    public function testCheckIsANoOpWhenNotEnabled(): void
    {
        $this->overrideAuthConfig(['login_require_confirmed' => false]);
        $contact = $this->createTestContact(['email' => 'gate-' . uniqid() . '@example.test']);

        // Unconfirmed and the gate disabled: must not throw.
        authLoginConfirmGate::check(new authEmailMethod(), $contact->getId());
        $this->assertTrue(true);
    }

    public function testCheckIsANoOpForAnExemptMethodEvenWhenEnabled(): void
    {
        $this->overrideAuthConfig([
            'login_require_confirmed' => true,
            'signup_confirm'          => true,
            'signup_fields'           => ['email'],
        ]);
        $contact = $this->createTestContact(['email' => 'gate-' . uniqid() . '@example.test']);

        // Unconfirmed email, gate enabled — but authPhoneMethod is exempt by
        // construction (fieldFor() returns null for it), so this must not
        // throw regardless of the email's status.
        authLoginConfirmGate::check(new authPhoneMethod(), $contact->getId());
        $this->assertTrue(true);
    }

    public function testCheckThrowsAGuardExceptionNeverACredentialFailureException(): void
    {
        $this->overrideAuthConfig([
            'login_require_confirmed' => true,
            'signup_confirm'          => true,
            'signup_fields'           => ['email'],
        ]);
        $contact = $this->createTestContact(['email' => 'gate-' . uniqid() . '@example.test']);

        try {
            authLoginConfirmGate::check(new authEmailMethod(), $contact->getId());
            $this->fail('expected authLoginUnconfirmedException');
        } catch (authLoginUnconfirmedException $e) {
            $this->assertInstanceOf(authGuardException::class, $e);
            $this->assertNotInstanceOf(authCredentialFailureException::class, $e);
            $this->assertNotSame('', $e->resend_url);
        }
    }

    public function testCheckDoesNotThrowOnceTheEmailIsConfirmed(): void
    {
        $this->overrideAuthConfig([
            'login_require_confirmed' => true,
            'signup_confirm'          => true,
            'signup_fields'           => ['email'],
        ]);
        $email = 'gate-' . uniqid() . '@example.test';
        $contact = $this->createTestContact(['email' => $email]);
        authContactStatus::confirmEmail($contact->getId(), $email);

        authLoginConfirmGate::check(new authEmailMethod(), $contact->getId());
        $this->assertTrue(true);
    }
}
