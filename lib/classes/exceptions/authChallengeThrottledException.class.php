<?php

/**
 * A challenge plugin throws this from verify() when its own attempt limit
 * (typically authThrottle, as plugins/totp does) refuses the attempt without
 * checking the submitted response at all. authFrontendChallengeAction catches
 * it and renders the same generic backoff message
 * (authThrottle::blockedMessage()) every other throttled form in the app
 * shows, instead of "Incorrect code." — which, for a correct code entered
 * while the refusal window is still open, would read as the authenticator
 * app being wrong rather than as "wait and retry" (AUTH-660).
 *
 * Carries only retryAfter, never a message of the plugin's own: the wording
 * stays in one place, in the app's locale, so a plugin cannot accidentally
 * say more than the core does about why an attempt was refused (decision 6
 * of docs/adr/003-credential-throttle.md). Same shape as
 * authRecoveryThrottledException. Deliberately not an authGuardException —
 * it is not a block, and no existing `catch (authGuardException)` should
 * swallow it.
 */
class authChallengeThrottledException extends waException
{
    public int $retryAfter;

    public function __construct(int $retryAfter)
    {
        $this->retryAfter = $retryAfter;
        parent::__construct('Challenge attempt throttled', 429);
    }
}
