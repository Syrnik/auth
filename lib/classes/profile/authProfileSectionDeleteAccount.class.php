<?php

/**
 * Deleting one's own account.
 *
 * No waContactForm — getForm() stays null, inherited from
 * authProfileSectionBase. There is no field here: the section takes one
 * decision, and a decision is not a value with a validator.
 *
 * Governed by the app's own delete_account_enabled and not by the site map,
 * decision 1 of docs/adr/001-profile-config-boundaries.md — personal_fields
 * describes what a profile is made of and has no opinion on whether the profile
 * may cease to exist. Off by default: a site that has not thought about it
 * should not be offering it.
 *
 * The deletion itself is hard, through the framework's own path, and it is
 * deliberately the only thing deleteContact() does. Other policies are wanted
 * later — a grace period, a queue that erases after some days, anonymisation —
 * and they differ only in what happens at that one call, so a plugin or a
 * subclass replaces that method and inherits every guard above it unchanged.
 */
class authProfileSectionDeleteAccount extends authProfileSectionBase
{
    protected static $id = 'delete_account';

    /**
     * What the confirmation checkbox posts. A deletion never happens because a
     * request merely reached this endpoint.
     */
    const CONFIRM_FIELD = 'confirm';

    /** @var bool whether this request is the one that removed the account */
    private $deleted = false;

    public function getName(): string
    {
        return _w('Delete account');
    }

    public function isAvailable(): bool
    {
        return (bool)authConfig::get('delete_account_enabled');
    }

    /**
     * There is always exactly one thing to do here, so the section is never in
     * the "available but nothing entered yet" state the other sections use.
     */
    public function isEmpty(): bool
    {
        return false;
    }

    /**
     * Whether the account may go at all, or null when it may.
     *
     * An account the framework itself would keep is not one to offer a delete
     * button for: a backend user's contact is tied to rights, assignments and
     * history that this page knows nothing about, and removing it from the
     * frontend profile is not a decision the frontend profile should be making.
     */
    public function getRemovalLock(?int $index = null): ?string
    {
        if ($this->contact->getRights('webasyst', 'backend')) {
            return _w('Accounts with access to the backend cannot be deleted here. Ask an administrator.');
        }

        return null;
    }

    public function save(array $data, ?int $index = null): bool
    {
        $this->errors = [];

        if (!$this->isAvailable()) {
            $this->addError('', _w('Deleting the account is not available.'));
            return false;
        }

        $lock = $this->getRemovalLock();
        if ($lock !== null) {
            $this->addError('', $lock);
            return false;
        }

        if (empty($data[self::CONFIRM_FIELD])) {
            $this->addError(self::CONFIRM_FIELD, _w('Confirm that you want to delete your account.'));
            return false;
        }

        if (!$this->deleteContact()) {
            $this->addError('', _w('The account could not be deleted.'));
            return false;
        }

        $this->deleted = true;

        // The session outlives the contact otherwise, and the next request would
        // be made by a signed-in user who no longer exists.
        wa()->getAuth()->clearAuth();

        return true;
    }

    /**
     * There is no profile to go back to. Redrawing this section after a
     * successful save would render it from a contact that no longer exists, and
     * the profile page itself now belongs to nobody.
     */
    public function getRedirectAfterSave(): ?string
    {
        return $this->deleted ? authHelper::getLoginUrl() : null;
    }

    // -------------------------------------------------------------------------

    protected function getTemplateVars(string $mode, ?int $index = null): array
    {
        return parent::getTemplateVars($mode, $index) + [
            // So the checkbox and the check that reads it cannot drift apart.
            'confirm_field' => self::CONFIRM_FIELD,
            'removal_lock'  => $this->getRemovalLock(),
        ];
    }

    /**
     * Removes the account. The single point where the deletion policy lives —
     * see the class comment.
     *
     * waContact::delete() is the framework's own path: it fires contacts.delete
     * so every app clears what it stored about this person, and drops rights,
     * settings, app tokens and verification channel assets on the way
     * (waContactModel::delete(), wa-system/webasyst/lib/models/waContact.model.php:71).
     * Doing any of that by hand here would leave the parts nobody remembered.
     */
    protected function deleteContact(): bool
    {
        return (bool)$this->contact->delete();
    }
}
