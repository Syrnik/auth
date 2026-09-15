<?php

/**
 * Outcome of authThrottle::check()/hit() for one request: a plain DTO in the
 * spirit of authCallbackResult, not an exception — refusing a request is a
 * normal, expected outcome here, not an error condition.
 */
class authThrottleState
{
    /** Refuse the request outright. */
    public bool $blocked;

    /** Seconds until a refused request may be retried; 0 when not blocked. */
    public int $retryAfter;

    /** Show a captcha before accepting the next attempt. */
    public bool $captchaRequired;

    /** Attempts counted so far in the current window (informational; the
     * larger of the identifier and IP counts when both were checked). */
    public int $attempts;

    public function __construct(bool $blocked = false, int $retryAfter = 0, bool $captchaRequired = false, int $attempts = 0)
    {
        $this->blocked         = $blocked;
        $this->retryAfter      = $retryAfter;
        $this->captchaRequired = $captchaRequired;
        $this->attempts        = $attempts;
    }
}
