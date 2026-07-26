<?php

/**
 * The phone this visitor signs in with. Proven by a code sent to the new number
 * and typed back — a phone has no link to follow.
 *
 * See authProfileSectionLogin for everything the two login sections share, and
 * authProfileSectionPhone for what this field is when the domain does not sign
 * anyone in with it.
 */
class authProfileSectionLoginPhone extends authProfileSectionLogin
{
    protected static $id = 'login_phone';

    public function getName(): string
    {
        return _w('Sign-in phone');
    }

    public function getConfirmableField(): string
    {
        return 'phone';
    }

    public function usesCode(): bool
    {
        return true;
    }

    /**
     * Numbers are only comparable — and only findable — in the form they are
     * stored in, so a submitted number goes through the same normalization a
     * save would apply. authProfileValues::preparePhones() works on a list, and
     * this is the single-value edge of it.
     */
    protected function normalize(string $value): string
    {
        $list = [trim($value)];
        authProfileValues::preparePhones($list, (int)$this->contact->getId());

        return $list ? (string)$list[0]['value'] : '';
    }

    protected function sendProof(string $value, string $token, ?string $code): void
    {
        if ($code === null) {
            throw new waException('a phone confirmation without a code');
        }

        // Same channel the phone login method uses (authPhoneMethod::sendSms()),
        // so a site with SMS working for sign-in has it working for this too.
        (new waSMS())->send($value, sprintf(_w('Your confirmation code: %s'), $code));
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

    /**
     * Keeps the confirmation statuses of the other numbers, exactly as the
     * plain phone section does — see authProfileSectionPhone::prepareList().
     */
    protected function prepareList(&$list): void
    {
        authProfileValues::preparePhones($list, (int)$this->contact->getId());
    }
}
