<?php

/**
 * A section whose change is not stored on submit but has to be confirmed first.
 *
 * Decision 2 of docs/adr/001-profile-config-boundaries.md: an email or a phone
 * that is an active login method, and the password, have a different life cycle
 * from a contact field — the new value is proven before it replaces the old one,
 * through authFrontendConfirm / authFrontendChallenge. The save endpoint must
 * therefore be able to ask a section "is this yours to store, or does it start a
 * flow?" before it calls save().
 *
 * Kept a separate interface rather than a method on authProfileSection: the
 * majority of sections store their data directly and would carry a permanently
 * null method, and a section implementing this one is a statement about that
 * section that is worth reading in its `implements` clause.
 */
interface authProfileSectionConfirmable
{
    /**
     * Where to send the visitor instead of saving $data, or null when this
     * particular change can be stored right away.
     *
     * Null is the normal answer, not a fallback: the same section handles both
     * cases. Changing the email that is the login goes through confirmation;
     * changing a second, non-login email does not.
     */
    public function getConfirmationUrl(array $data, ?int $index = null): ?string;

    /**
     * The contact field this section's confirmable value belongs to ('email',
     * 'phone'), which is what a pending change is filed under in
     * auth_profile_confirm.
     *
     * The flow stores a field and a value and nothing else about the section,
     * so this is how a confirmed row finds its way back to the code that knows
     * what the value means.
     */
    public function getConfirmableField(): string;

    /**
     * Writes a value that has now been proven, and reports success; failures
     * are readable from getErrors() as usual.
     *
     * The other half of getConfirmationUrl(): the flow (authFrontendMyConfirm)
     * owns tokens, codes and expiry, and knows nothing about what an email or a
     * phone is. Where in the value list the confirmed value goes, and what else
     * has to hold before it may go there, stays with the section — the same
     * place that decided the change needed proving.
     *
     * Called with time having passed since the change was requested, so a
     * section must re-check whatever it checked then rather than trust the
     * pending row.
     */
    public function applyConfirmedValue(string $value): bool;
}
