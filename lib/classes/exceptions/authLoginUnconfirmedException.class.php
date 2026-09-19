<?php

/**
 * Thrown by authLoginConfirmGate::check() to block a sign-in whose login
 * value (authLoginConfirmGate::fieldFor()) has never been confirmed —
 * decision 3 of docs/adr/005-value-confirmation.md.
 *
 * Extends authGuardException, deliberately NOT authCredentialFailureException:
 * the visitor typed a correct password, this is not a credential mismatch,
 * and authLogin.controller.php only feeds the brute-force throttle on the
 * latter (see that exception's own docblock and decision 1 of
 * docs/adr/003-credential-throttle.md) — a legitimate owner whose account
 * predates this feature must not spend their throttle budget on a state
 * that is entirely the domain's own configuration, not anything they did
 * wrong.
 *
 * @see waAuth::mustNeedConfirmSignup() (wa-system/auth/waAuth.class.php) —
 *      the framework's own equivalent gate for waAuth-driven logins, whose
 *      shape (block on unconfirmed, offer a resend) this mirrors without
 *      touching wa-system/ itself.
 */
class authLoginUnconfirmedException extends authGuardException
{
    /**
     * Where the login form's "resend" link points — confirm/, see
     * authFrontendConfirmAction's resend half. Set by the caller
     * (authLoginConfirmGate::check()) right after construction, since the
     * route helper is not something this exception class should know about
     * on its own.
     */
    public string $resend_url = '';
}
