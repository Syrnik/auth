<?php

/**
 * The strict-login gate — decision 3 of docs/adr/005-value-confirmation.md:
 * a domain may require the login value (email, for now — see fieldFor()) to
 * be status = confirmed before a sign-in with it succeeds.
 *
 * Off by default (authConfig::isLoginRequireConfirmed()) — flipping it on
 * for an existing domain must not silently lock out every account whose
 * login was never proven under the old, silent behavior.
 */
final class authLoginConfirmGate
{
    /**
     * Whether the gate applies on this domain at all. Two conditions besides
     * the flag itself, both load-bearing:
     *
     *   - signup_confirm must be on. authFrontendRegisterAction auto-signs a
     *     new contact in directly when it is off (never calling authenticate(),
     *     so the gate never even runs for that first sign-in) — but every
     *     login *after* that one does go through the gate, and such an
     *     account's email was never asked to be proven at all. Gating on
     *     signup_confirm keeps "prove it before you may sign in" and "we ask
     *     for proof at signup" the same decision, so the admin cannot enable
     *     one without the other and brick every account signed up in between.
     *   - 'email' must actually be in signup_fields — a domain that never
     *     collects it at signup has nothing for signup_confirm to have asked
     *     about either.
     *
     * Both read here, in one place, rather than validated against each other
     * on the backend Login/Signup screens: an admin can change signup_confirm
     * on the Signup screen after already enabling this on the Login screen,
     * and a check that only runs at save time on one screen cannot see a
     * change made on the other. Reading both live, every time, means there
     * is no sequencing to get wrong.
     */
    public static function isEnabled(?string $domain = null): bool
    {
        if (!authConfig::isLoginRequireConfirmed($domain)) {
            return false;
        }
        if (!authConfig::get('signup_confirm', true, $domain)) {
            return false;
        }

        $fields = (array)authConfig::get('signup_fields', [], $domain);
        return in_array('email', $fields, true);
    }

    /**
     * The contact field to check for this method, or null when the gate
     * does not apply to it at all.
     *
     * Only authEmailMethod — a stored, previously-proven credential (the
     * password) checked against a login value that may never have been
     * proven itself. Every other built-in is excluded on purpose:
     *
     *   - authPhoneMethod: its own success (a correct OTP) IS the proof —
     *     authPhoneMethod::verifyOtp() stamps confirmed in the same request
     *     this gate would otherwise have to run inside. Gating it would mean
     *     blocking the very login that proves the number.
     *   - authLoginMethod (wa_contact.login): there is no confirmation
     *     storage for this field anywhere in the framework — nothing to
     *     check, so nothing to gate.
     *   - OAuth/plugin methods: the provider vouches for the identity by a
     *     mechanism this app does not model as "confirmed"/"unconfirmed" at
     *     all (see authWaidAdapter's own comment on trusting the provider's
     *     verified emails).
     *
     * Deliberately not a method on the authMethod interface itself — that
     * interface is a plugin extension point, and a new required method would
     * break every third-party implementer for a check only one built-in
     * method needs.
     */
    public static function fieldFor(authMethod $method): ?string
    {
        return $method instanceof authEmailMethod ? 'email' : null;
    }

    /**
     * Blocks the sign-in with authLoginUnconfirmedException when this
     * domain's gate applies to $method and $contact_id's value for that
     * field is not confirmed. A no-op otherwise — including when the gate is
     * simply not enabled, so a caller can call this unconditionally after
     * authenticate() succeeds without checking isEnabled() itself first.
     */
    public static function check(authMethod $method, int $contact_id): void
    {
        if (!self::isEnabled()) {
            return;
        }

        $field = self::fieldFor($method);
        if ($field === null) {
            return;
        }

        if (authContactStatus::isPrimaryConfirmed($field, $contact_id)) {
            return;
        }

        $exception = new authLoginUnconfirmedException(
            _w('Please confirm your email before signing in. We can send a new link.')
        );
        $exception->resend_url = wa()->getRouteUrl('auth/frontend/confirm', [], true);

        throw $exception;
    }
}
