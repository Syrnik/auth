<?php

/**
 * Session marker that turns an OAuth round trip started from the profile's
 * "Linked accounts" section (my/link/<method_id>/) into a link, instead of
 * the plain login/signup that the same callback route handles otherwise.
 *
 * The marker must not survive a login round it was not meant for — a
 * visitor who abandons a link attempt and later signs in normally (as
 * themselves or someone else) must get a plain login, not a link. get()
 * enforces this by re-checking, on every read, that the session is still
 * authenticated as the exact contact the marker was minted for; a mismatch
 * or expiry silently drops the marker rather than acting on it. There is no
 * other identity check (state/nonce) here because the marker alone never
 * authorizes anything — authContactResolver::link() still requires a valid
 * provider round trip before it writes anything.
 */
class authLinkIntent
{
    const SESSION_KEY = 'auth_link_intent';
    const TTL         = 900; // 15 minutes: WAID's own redirect + a slow consent screen.

    /** @var string|null outcome of the linker in THIS request only: 'linked'|'already_linked' */
    private static $outcome = null;

    public static function start(int $contact_id, string $method_id, string $source): void
    {
        wa()->getStorage()->set(self::SESSION_KEY, [
            'contact_id' => $contact_id,
            'method_id'  => $method_id,
            'source'     => $source,
            'time'       => time(),
        ]);
    }

    /**
     * Returns the intent if it is still valid for the current visitor, null
     * otherwise. A stale, foreign, or malformed marker is cleared right away
     * so it cannot be read again by a later request.
     */
    public static function get(): ?array
    {
        $intent = wa()->getStorage()->get(self::SESSION_KEY);
        if (!is_array($intent)
            || empty($intent['contact_id'])
            || empty($intent['method_id'])
            || empty($intent['source'])
            || empty($intent['time'])
        ) {
            return null;
        }

        if (time() - (int) $intent['time'] > self::TTL) {
            self::clear();
            return null;
        }

        if (!wa()->getUser()->isAuth() || (int) wa()->getUser()->getId() !== (int) $intent['contact_id']) {
            self::clear();
            return null;
        }

        return $intent;
    }

    /**
     * get() filtered to a specific OAuth source — what the callback sites use
     * to decide whether the identity just returned by the provider is the one
     * the visitor set out to link.
     */
    public static function match(string $source): ?array
    {
        $intent = self::get();
        if ($intent === null || $intent['source'] !== $source) {
            return null;
        }
        return $intent;
    }

    public static function clear(): void
    {
        wa()->getStorage()->del(self::SESSION_KEY);
    }

    public static function setOutcome(string $outcome): void
    {
        self::$outcome = $outcome;
    }

    public static function getOutcome(): ?string
    {
        return self::$outcome;
    }
}
