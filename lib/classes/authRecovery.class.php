<?php

/**
 * Thin coordinator over authRecoveryProvider — knows nothing about email or
 * phone, only about the interface. See docs/adr/004-recovery-channels.md.
 *
 * Two different entry points for finishing a request, not two branches of
 * one method, because the two kinds of provider differ in where their state
 * has to live:
 *
 * - A link-based provider (needsCode() === false) is inherently
 *   cross-session: the mailed link is opened on whatever device has the mail
 *   client, often a different browser session than the one that asked for
 *   it. Its state lives in the auth_password_recovery row itself, addressed
 *   by the token in the URL — completeByToken() reads the row's own
 *   'channel' column to find the provider, so accepting a token from the URL
 *   never lets the caller choose which provider handles it.
 * - A code-based provider (needsCode() === true) is entered on the same
 *   page, in the same session, right after request(). Its handle
 *   (channel + token) is kept in wa()->getStorage(), never accepted from the
 *   client — verifyCode()/complete() read it from there, so a submitted code
 *   can never be routed to a different provider's (possibly weaker) check.
 */
class authRecovery
{
    private const SESSION_KEY = 'auth_recovery_handle';

    /**
     * Starts a recovery request. Throttled by IP only, scope 'recovery' —
     * never by the identifier, which would hand anyone who merely knows a
     * victim's email/phone a lever to lock them out of their own recovery
     * (decision 4 of docs/adr/003-credential-throttle.md, unchanged here).
     * The response depends only on the claiming provider's needsCode() —
     * never on whether the identifier actually resolved to a contact, which
     * is the anti-enumeration property every provider's start() is
     * contractually required to uphold (decision 5 of the ADR).
     */
    public static function request(string $identifier): authRecoveryResponse
    {
        $identifier = trim($identifier);

        $throttle_keys = ['ip' => waRequest::getIp()];
        $state = authThrottle::check('recovery', $throttle_keys);
        if ($state->blocked) {
            return new authRecoveryResponse(false, authThrottle::blockedMessage($state->retryAfter));
        }

        $provider = self::claimingProvider($identifier);
        if (!$provider) {
            // A format rejection, not an existence answer: it depends only
            // on recovery_channels, never on any account, so it costs no
            // throttle hit — same as the old email-only form's "enter a
            // valid email" branch.
            return new authRecoveryResponse(false, self::unrecognizedIdentifierMessage());
        }

        // Counts the request itself, not a failure — every accepted request
        // past this point risks delivering a message, whether or not the
        // identifier turns out to belong to a real account.
        authThrottle::hit('recovery', $throttle_keys);

        try {
            $handle = $provider->start($identifier);
        } catch (authRecoveryThrottledException $e) {
            // The provider's own resource cap (e.g. otp_send) refused this
            // attempt. Rendered exactly like this method's own throttle
            // refusal above — the provider is contractually required to have
            // keyed retryAfter on the identifier alone, so this message is
            // already identical for a real and a made-up identifier without
            // this method needing to know which provider or which resource.
            return new authRecoveryResponse(false, authThrottle::blockedMessage($e->retryAfter));
        }

        if ($provider->needsCode()) {
            wa()->getStorage()->set(self::SESSION_KEY, ['channel' => $handle->channel, 'token' => $handle->token]);
        }

        return new authRecoveryResponse($provider->needsCode());
    }

    /**
     * Finishes a link-based provider's request (recovery/<token>/). The
     * provider is whichever one the row itself names, not chosen by the
     * caller — see this class's own docblock.
     */
    public static function completeByToken(string $token): ?int
    {
        $row = (new authPasswordRecoveryModel())->getValid($token);
        if (!$row) {
            return null;
        }

        $provider = authPluginManager::getRecoveryProvider((string)$row['channel']);
        if (!$provider || $provider->needsCode()) {
            // A code-based provider's token never completes on its own —
            // this address is not how it is reached.
            return null;
        }

        return $provider->complete(new authRecoveryHandle((string)$row['channel'], $token));
    }

    /**
     * Whether the current session has a code-entry step pending — the
     * action reads this to decide which step of recovery/ to render, without
     * knowing which provider issued the handle.
     */
    public static function hasPendingCode(): bool
    {
        return self::currentHandle() !== null;
    }

    /**
     * Checks a code against the handle request() stashed in the session.
     * False for a missing/foreign handle as well as a genuinely wrong code —
     * the caller shows the same message either way.
     */
    public static function verifyCode(string $code): bool
    {
        $handle = self::currentHandle();
        if (!$handle) {
            return false;
        }

        $provider = authPluginManager::getRecoveryProvider($handle->channel);
        if (!$provider || !$provider->needsCode()) {
            return false;
        }

        return $provider->verifyCode($handle, $code);
    }

    /**
     * Finishes a code-based provider's request, once verifyCode() has
     * already succeeded for the session's current handle.
     */
    public static function complete(): ?int
    {
        $handle = self::currentHandle();
        if (!$handle) {
            return null;
        }

        $provider = authPluginManager::getRecoveryProvider($handle->channel);
        if (!$provider || !$provider->needsCode()) {
            return null;
        }

        $contact_id = $provider->complete($handle);
        self::clearHandle();

        return $contact_id;
    }

    public static function clearHandle(): void
    {
        wa()->getStorage()->del(self::SESSION_KEY);
    }

    /**
     * Guesses left on the session's current pending code, for telling the
     * visitor before they run out — same courtesy
     * authPhoneMethod::verifyOtp() already extends to a login OTP. Reads the
     * shared auth_password_recovery row directly rather than going through a
     * provider: the attempt count lives on the row itself, identically for
     * every code-based provider, real or decoy. Null when there is nothing
     * pending, or the pending request does not use a code at all.
     */
    public static function attemptsLeftForCurrentCode(): ?int
    {
        $handle = self::currentHandle();
        if (!$handle) {
            return null;
        }

        $model = new authPasswordRecoveryModel();
        $row   = $model->getValid($handle->token);
        if (!$row || !$model->needsCode($row)) {
            return null;
        }

        return $model->attemptsLeft($row);
    }

    // -------------------------------------------------------------------------

    private static function claimingProvider(string $identifier): ?authRecoveryProvider
    {
        foreach (authPluginManager::getRecoveryProviders() as $provider) {
            if ($provider->claims($identifier)) {
                return $provider;
            }
        }
        return null;
    }

    private static function currentHandle(): ?authRecoveryHandle
    {
        $stored = wa()->getStorage()->get(self::SESSION_KEY);
        if (!is_array($stored) || empty($stored['channel']) || empty($stored['token'])) {
            return null;
        }
        return new authRecoveryHandle((string)$stored['channel'], (string)$stored['token']);
    }

    private static function unrecognizedIdentifierMessage(): string
    {
        return _w('Enter a valid email address or phone number.');
    }
}
