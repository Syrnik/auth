<?php

/**
 * A provider throws this from start() when its own resource throttle (a
 * paid-resource cap like authPhoneRecoveryProvider's otp_send, or anything
 * else a provider chooses to meter) refuses this attempt. authRecovery
 * catches it and renders the same generic backoff message
 * (authThrottle::blockedMessage()) it would for its own IP-scoped 'recovery'
 * throttle.
 *
 * $retryAfter MUST come from a counter keyed on the identifier as typed (or
 * something derived from it), never from whether the identifier resolved to
 * a contact — a real number's cap must be indistinguishable from a made-up
 * one's by how soon repeated attempts start being refused (decision 5 of
 * docs/adr/004-recovery-channels.md). authPhoneRecoveryProvider checks this
 * before ever resolving a contact, for exactly this reason.
 */
class authRecoveryThrottledException extends waException
{
    public int $retryAfter;

    public function __construct(int $retryAfter)
    {
        $this->retryAfter = $retryAfter;
        parent::__construct('Recovery request throttled', 429);
    }
}
