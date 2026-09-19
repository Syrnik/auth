<?php

/**
 * Email as a plain contact field: several addresses, each of an optional type,
 * saved the moment they are submitted.
 *
 * Available only while email is NOT a login method for this domain. When it is,
 * authProfileSectionLoginEmail takes over instead — decision 2 of
 * docs/adr/001-profile-config-boundaries.md: the same string means two different
 * things depending on the domain's login_methods, and a class that has to ask
 * which one it is on every method is two classes wearing one name.
 *
 * The two are mutually exclusive by availability, so the page never shows the
 * field twice.
 *
 * Decision 1 of docs/adr/005-value-confirmation.md, superseding ADR 001's own
 * decision 2 on this point: a secondary address is proven too, just not by
 * withholding it. It saves immediately, same as before this decision — nothing
 * about isAvailable()/isEmpty()/save() below changed for that — and
 * getConfirmationUrl() answers null unconditionally, because there is nothing
 * left to withhold. Proving happens afterwards and separately, through
 * sendConfirmation() (authFrontendMyConfirmSendController, my/confirm/send/) and
 * applyConfirmedValue() below, which only stamps status — the value is already
 * where it belongs.
 */
class authProfileSectionEmail extends authProfileSectionMultiField implements authProfileSectionConfirmable
{
    use authProfileConfirmableValueTrait;
    use authProfileConfirmEmailTrait;

    protected static $id = 'email';

    public function getName(): string
    {
        return _ws('Email');
    }

    /**
     * The site allows the field and the domain does not sign anyone in with it.
     */
    public function isAvailable(): bool
    {
        return parent::isAvailable() && !authHelper::isLoginField('email');
    }

    /**
     * Always null: the value is stored on submit, exactly as before this
     * field became confirmable at all. authFrontendMySaveController reads null
     * as "mine to store now" and saves normally — nothing is ever withheld
     * here, unlike the login twin.
     */
    public function getConfirmationUrl(array $data, ?int $index = null): ?string
    {
        return null;
    }

    /**
     * The proof has come back for a value that was already on the contact —
     * nothing to write, only the status to stamp.
     */
    public function applyConfirmedValue(string $value): bool
    {
        $this->errors = [];

        if (!$this->isAvailable()) {
            $this->addError('', _w('This change can no longer be applied.'));
            return false;
        }

        if (!$this->stampConfirmed($this->normalize($value))) {
            $this->addError('', _w('This address is no longer on your profile.'));
            return false;
        }

        return true;
    }

    protected function getTemplateVars(string $mode, ?int $index = null): array
    {
        return parent::getTemplateVars($mode, $index) + $this->confirmableTemplateVars();
    }
}
