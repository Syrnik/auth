<?php

/**
 * A real credential mismatch — wrong password, unknown login — as opposed to
 * every other reason authGuardException gets thrown today (an empty form, a
 * plugin's own resource limit). See decision 1 of
 * docs/adr/003-credential-throttle.md: authLoginController counts a hit
 * against the throttle only for this exception, never for the parent class,
 * so a blank submit or authPhoneMethod's own OTP-resend cap don't silently
 * feed the same counter.
 *
 * Extends authGuardException on purpose — every existing
 * `catch (authGuardException $e)` keeps working unchanged; only the new
 * `catch (authCredentialFailureException $e)` above it needs to exist for the
 * distinction to matter.
 */
class authCredentialFailureException extends authGuardException
{
}
