<?php

/**
 * A channel of password recovery — the plugin extension point AUTH-48
 * introduces, in the same family as authMethod/authGuard/authCaptcha/
 * authThrottleStore. See docs/adr/004-recovery-channels.md.
 *
 * The core does not know what an "email" or a "phone" is: it only knows this
 * interface. Both built-in channels (authEmailRecoveryProvider,
 * authPhoneRecoveryProvider) implement it exactly the way a third-party
 * plugin would, and are registered by authPluginManager under the ids
 * 'email'/'phone' — no privileged path inside authRecovery. A plugin
 * authenticating by a combined "email or phone" field, or by any identifier
 * of its own, implements this interface to decide for itself what it was
 * given and how to resolve/deliver against it; the core never classifies an
 * identifier centrally (decision 1/3 of the ADR).
 *
 * Credential boundary: a provider owns identifier → contact resolution and
 * proof delivery only. It never touches wa_contact.password — complete()
 * hands back a contact_id, and the core sets the new password on it. Without
 * this boundary authHelper::hasPasswordLogin() (the "is there something to
 * reset" half of the availability gate) stops meaning anything, because a
 * provider could establish access to an account that was never gated on
 * having a password to begin with.
 *
 * Anti-enumeration is a contract requirement, not something the core can
 * verify on a plugin's behalf (decision 5): start() and complete() MUST
 * behave identically — same shape, same rough timing — whether or not the
 * identifier resolves to a real contact. The core can only test its own two
 * built-in providers against this; a third-party provider that gets it wrong
 * reopens exactly the oracle this interface exists to close.
 */
interface authRecoveryProvider
{
    /**
     * The provider's id in recovery_channels — 'email'/'phone' for the
     * built-ins, otherwise the same shape login_methods uses for a plugin
     * ('myplugin_plugin', a named instance 'oidc_plugin:gitlab').
     */
    public function getId(): string;

    /**
     * Syntactic ownership of a raw identifier, without touching a contact.
     * The core tries providers in recovery_channels order and uses the first
     * one whose claims() returns true (decision 3) — not the first one that
     * resolves a contact, which would require resolving one to decide, i.e.
     * build an oracle.
     */
    public function claims(string $identifier): bool;

    /**
     * Starts recovery for an identifier this provider has already claimed.
     * MUST behave identically whether or not the identifier resolves to a
     * real contact, including timing and the returned handle's shape — see
     * this interface's class-level note. Never throws for "not found"; that
     * case is handled the same as "found" internally.
     *
     * MAY throw authRecoveryThrottledException when the provider's own
     * resource throttle (a paid-resource cap like SMS spend) refuses this
     * attempt — the core catches it and renders the same generic backoff
     * message it would for its own IP-scoped throttle. The exception's
     * retryAfter MUST be computed from a counter keyed on the identifier as
     * typed, never from whether it resolves, for the same reason the rest of
     * this method must not distinguish the two: a real identifier's cap must
     * be indistinguishable from a made-up one's by how soon repeats get
     * refused (decision 5 of the ADR). Throw this before resolving a contact
     * at all, so a blocked attempt never pays the resolution's own timing
     * cost either.
     */
    public function start(string $identifier): authRecoveryHandle;

    /**
     * true: the core shows a code-entry step and later calls verifyCode().
     * false: the core shows a generic "sent" page; the token alone (usually
     * mailed as a link) is the proof, delivered straight to complete().
     */
    public function needsCode(): bool;

    /**
     * Checks a submitted code against a handle from start(). Only called
     * when needsCode() is true. A decoy handle (identifier did not resolve)
     * MUST fail indistinguishably from a wrong code against a real one — same
     * attempt counting, same message, same fate once attempts run out.
     */
    public function verifyCode(authRecoveryHandle $handle, string $code): bool;

    /**
     * The proof is accepted (a valid link token, or a valid link token plus a
     * verified code). Returns the contact_id to set the new password on, or
     * null for an expired/invalid handle or a decoy — the core shows the same
     * response for both, so complete() must not distinguish them either.
     */
    public function complete(authRecoveryHandle $handle): ?int;
}
