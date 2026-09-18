<?php

/**
 * Outcome of authRecovery::request(), in the spirit of authThrottleState: a
 * plain DTO, not an exception. Deliberately the level tests compare against
 * (see authRecoveryTest) rather than any richer internal state — comparing at
 * a lower level can stay green while the actually-rendered page still
 * diverges between "identifier resolved" and "identifier did not", which is
 * exactly the oracle docs/adr/004-recovery-channels.md decision 5 exists to
 * close.
 */
class authRecoveryResponse
{
    /** Show a code-entry step next (the claimed provider's needsCode()). */
    public bool $needsCode;

    /** Format/throttle error to show instead of any success state; '' when none. */
    public string $error;

    public function __construct(bool $needsCode = false, string $error = '')
    {
        $this->needsCode = $needsCode;
        $this->error     = $error;
    }
}
