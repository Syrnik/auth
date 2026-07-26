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
 */
class authProfileSectionEmail extends authProfileSectionMultiField
{
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
}
