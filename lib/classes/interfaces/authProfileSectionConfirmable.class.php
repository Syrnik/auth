<?php

/**
 * A section whose change is not stored on submit but has to be confirmed first.
 *
 * Decision 2 of docs/adr/001-profile-config-boundaries.md: an email or a phone
 * that is an active login method has a different life cycle from a contact
 * field — the new value is proven before it replaces the old one, through
 * auth_profile_confirm and authFrontendMyConfirmAction. The save endpoint must
 * therefore be able to ask a section "is this yours to store, or does it start a
 * flow?" before it calls save().
 *
 * The password is not one of these, though the plan had it here: there is
 * nothing to send a new password to, and what proves the change is the current
 * password (authProfileSectionPassword::validateSection()).
 *
 * Kept a separate interface rather than a method on authProfileSection: the
 * majority of sections store their data directly and would carry a permanently
 * null method, and a section implementing this one is a statement about that
 * section that is worth reading in its `implements` clause.
 *
 * Extends authProfileSection rather than standing alone: every real
 * implementer already implements both (a section whose changes are never
 * confirmable makes no sense), and authFrontendMyConfirmSendController reads
 * getId()/getErrors()/render() straight off a value typed only as
 * authProfileSectionConfirmable — those live on authProfileSection, so
 * without this the interface alone cannot promise they exist.
 */
interface authProfileSectionConfirmable extends authProfileSection
{
    /**
     * Where to send the visitor instead of saving $data, or null when this
     * particular change can be stored right away.
     *
     * Two sections answer null, for two different reasons — decision 1 of
     * docs/adr/005-value-confirmation.md. A login section (authProfileSectionLogin)
     * answers null for a second address, an unchanged value, or a removal: none
     * of those is a new login value to prove. A plain section
     * (authProfileSectionEmail/Phone) answers null *always*: its value is
     * stored on submit regardless, and proving it happens afterwards and
     * separately, through sendConfirmation() (authProfileConfirmableValueTrait),
     * not through this method at all.
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

    /**
     * Starts (or restarts) confirmation of the stored value at $index —
     * my/confirm/send/<section>/ (authFrontendMyConfirmSendController), the
     * explicit "Confirm" action a plain section's already-stored value goes
     * through, per decision 1 of docs/adr/005-value-confirmation.md. Never
     * withholds anything: whatever the outcome, the value stays on the
     * contact exactly as it is — only applyConfirmedValue()'s eventual stamp
     * changes anything.
     *
     * Returns the same shape as getConfirmationUrl(): a URL to send the
     * visitor to, or null with the reason on getErrors(). No index (or one
     * past the end of the list) is itself a reason to answer null — "there is
     * nothing to confirm" is as much a failure to report as a delivery error.
     */
    public function sendConfirmation(?int $index): ?string;

    /**
     * Whether the stored value at $index already carries status = confirmed.
     * Read by the "Confirm" endpoint to skip re-sending a proof for a value
     * already proven, and by a theme partial deciding whether to show a
     * confirmed chip or the "Confirm" action.
     */
    public function isConfirmed(?int $index = null): bool;
}
