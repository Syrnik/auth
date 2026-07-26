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
}
