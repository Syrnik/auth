<?php

/**
 * The phone this visitor signs in with. Proven by a code sent to the new number
 * and typed back — a phone has no link to follow.
 *
 * See authProfileSectionLogin for everything the two login sections share, and
 * authProfileSectionPhone for what this field is when the domain does not sign
 * anyone in with it. The proof-delivery mechanics (getConfirmableField(),
 * usesCode(), normalize(), sendProof(), stampConfirmed(), prepareList()) come
 * from authProfileConfirmPhoneTrait, shared with authProfileSectionPhone.
 */
class authProfileSectionLoginPhone extends authProfileSectionLogin
{
    use authProfileConfirmPhoneTrait;

    protected static $id = 'login_phone';

    public function getName(): string
    {
        return _w('Sign-in phone');
    }

    /**
     * Whether another account already signs in with this number. Mirrors
     * authPhoneMethod::findByPhone(): the login is the primary number
     * (sort = 0) of an account that can sign in at all.
     */
    protected function isTaken(string $value): bool
    {
        $contact_id = (int)$this->contact->getId();

        $sql = "SELECT c.id FROM wa_contact c
                JOIN wa_contact_data d ON c.id = d.contact_id AND d.field = 'phone'
                WHERE d.value = s:phone
                  AND d.sort = 0
                  AND c.id != i:id
                  AND c.password != ''
                  AND c.is_user > -1
                LIMIT 1";

        return (bool)(new waContactModel())
            ->query($sql, ['phone' => $value, 'id' => $contact_id])
            ->fetchField('id');
    }
}
