<?php

/**
 * Phone as a plain contact field, the counterpart of authProfileSectionEmail:
 * several numbers, each of an optional type, saved on submit. Available only
 * while phone is NOT a login method — see authProfileSectionLoginPhone and
 * decision 2 of docs/adr/001-profile-config-boundaries.md.
 */
class authProfileSectionPhone extends authProfileSectionMultiField
{
    protected static $id = 'phone';

    public function getName(): string
    {
        return _ws('Phone');
    }

    public function isAvailable(): bool
    {
        return parent::isAvailable() && !authHelper::isLoginField('phone');
    }

    /**
     * Normalizes the numbers and keeps the confirmation status each of them
     * already had — see authProfileValues::preparePhones().
     *
     * This is the reason a phone cannot just be written like any other field:
     * the status lives beside the value in wa_contact_data, nothing in the form
     * carries it, and a plain save would file every number as unconfirmed. A
     * number verified by SMS would quietly stop being verified because the
     * visitor corrected the one below it.
     */
    protected function prepareList(&$list): void
    {
        authProfileValues::preparePhones($list, (int)$this->contact->getId());
    }
}
