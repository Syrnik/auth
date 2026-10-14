<?php

/**
 * CSRF protection for the app's anonymous forms — login, signup, recovery,
 * the signup-confirmation resend and the 2FA challenge (AUTH-573, see
 * docs/adr/007-anonymous-csrf.md).
 *
 * The framework covers none of them: waDispatch::dispatchFrontend() checks
 * _csrf only on 'secure' routes, and the _csrf cookie it would compare
 * against is set by waAuthUser for signed-in visitors alone. So this issues
 * that same cookie to an anonymous visitor too and checks it on POST itself
 * — a double-submit token, bound to the browser by the cookie. Reusing the
 * framework's own cookie rather than a second one is deliberate: every
 * {$wa->csrf()} in the themes and in plugin templates (captcha, totp's
 * challenge form) prints it unchanged, and waAuthUser keeps it after sign-in
 * because it only ever sets the cookie when it is missing.
 */
class authCsrf
{
    private const NAME = '_csrf';

    /**
     * The visitor's token, issuing one first if the browser has none. Also
     * written into $_COOKIE, so a form rendered by this very request — the
     * visitor's first GET, or a re-render after a rejected POST — already
     * prints it via {$wa->csrf()} and waRequest::cookie().
     */
    public static function token(): string
    {
        $token = (string)waRequest::cookie(self::NAME, '');
        if ($token !== '') {
            return $token;
        }

        $token = bin2hex(random_bytes(16));
        wa()->getResponse()->setCookie(self::NAME, $token);
        $_COOKIE[self::NAME] = $token;

        return $token;
    }

    /**
     * Whether this POST carries the token its browser holds. Both must be
     * present: an empty field matching an absent cookie is exactly the
     * '' == '' hole the framework's own comparison has for anonymous
     * visitors. The field and the X-CSRF-Token header are accepted alike,
     * as waDispatch::dispatch() does.
     */
    public static function isValid(): bool
    {
        $cookie = (string)waRequest::cookie(self::NAME, '');
        if ($cookie === '') {
            return false;
        }

        foreach ([waRequest::post(self::NAME, ''), waRequest::server('HTTP_X_CSRF_TOKEN', '')] as $sent) {
            if (is_string($sent) && $sent !== '' && hash_equals($cookie, $sent)) {
                return true;
            }
        }

        return false;
    }

    /**
     * What a rejected POST tells the visitor. The form is re-rendered with
     * a freshly issued token, so submitting again simply works — the common
     * real-world cause is a form left open past the cookie, not an attack.
     */
    public static function errorMessage(): string
    {
        return 'Форма устарела. Отправьте её ещё раз.';
    }
}
