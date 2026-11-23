<?php

/**
 * The session mark "a login step began behind a passed captcha" (AUTH-856).
 *
 * A stepped method (authSteppedMethod — phone OTP: number, then code) takes
 * two or more POSTs. The captcha guards the one that starts the step; the
 * ones that only continue it are already capped by the method's own limits
 * (authPhoneMethod: 5 guesses per code, a 300 s code TTL), and asking for a
 * second captcha there either makes the visitor solve it twice (single-use
 * reCAPTCHA tokens) or fails the sign-in outright.
 *
 * Lives in the session, never in the POST: a client-side "skip the captcha"
 * flag is one anybody can set. Bound to the method instance and the step's
 * subject, never extended: begin() is only called when the captcha actually
 * passed (or wasn't required), so a wrong code that re-raises the step keeps
 * the original deadline instead of rolling it forward.
 */
final class authLoginStepPass
{
    public const SESSION_KEY = 'auth_login_step_pass';

    /**
     * Matches authPhoneMethod's code TTL: a pass outliving the code it was
     * issued for would only cover a step that can no longer succeed.
     */
    public const TTL_SECONDS = 300;

    public static function begin(string $method_id, string $subject): void
    {
        if ($subject === '') {
            self::clear();
            return;
        }
        wa()->getStorage()->set(self::SESSION_KEY, [
            'method'  => $method_id,
            'subject' => $subject,
            'expires' => time() + self::TTL_SECONDS,
        ]);
    }

    public static function isLive(string $method_id, string $subject): bool
    {
        $pass = wa()->getStorage()->get(self::SESSION_KEY);
        if (!is_array($pass) || $subject === '') {
            return false;
        }
        if (time() > (int)($pass['expires'] ?? 0)) {
            self::clear();
            return false;
        }
        return ($pass['method'] ?? null) === $method_id
            && ($pass['subject'] ?? null) === $subject;
    }

    public static function clear(): void
    {
        wa()->getStorage()->del(self::SESSION_KEY);
    }
}
