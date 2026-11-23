<?php

/**
 * A form login method whose sign-in takes more than one POST — the first one
 * starts a step (e.g. sends a code), the next ones continue it (verify the
 * code). Optional: authLoginController asks only methods implementing it.
 *
 * What it buys: the captcha is solved once, on the POST that starts the step,
 * and not again on its continuations (AUTH-856) — see authLoginStepPass. A
 * method that doesn't implement this keeps the captcha on every POST, which
 * is the safe default.
 */
interface authSteppedMethod
{
    /**
     * Who the step is for, in the one form both the starting POST and its
     * continuations resolve to (e.g. the normalized phone number) — the pass
     * a step leaves behind is bound to it, so changing the number brings the
     * captcha back. '' when the POST names nobody: no pass is issued then.
     */
    public function getStepSubject(array $params): string;

    /**
     * Whether this POST only continues a step already begun and cannot reach
     * anything the captcha is there to protect — must be false for whatever
     * starts or restarts a step (a send or resend of a paid SMS, say), since
     * a live pass lets a continuation skip the captcha.
     */
    public function isStepContinuation(array $params): bool;
}
