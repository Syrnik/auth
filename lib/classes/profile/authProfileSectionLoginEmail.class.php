<?php

/**
 * The email this visitor signs in with. Proven by a link mailed to the new
 * address — receiving it is the proof, so there is nothing to type back.
 *
 * See authProfileSectionLogin for everything the two login sections share, and
 * authProfileSectionEmail for what this field is when the domain does not sign
 * anyone in with it.
 */
class authProfileSectionLoginEmail extends authProfileSectionLogin
{
    protected static $id = 'login_email';

    public function getName(): string
    {
        return _w('Sign-in email');
    }

    public function getConfirmableField(): string
    {
        return 'email';
    }

    public function usesCode(): bool
    {
        return false;
    }

    protected function normalize(string $value): string
    {
        // Mailbox names are case-sensitive in the standard and never in
        // practice; the domain never is. Comparing as typed would let the same
        // address be "new" because of a capital letter.
        return mb_strtolower(trim($value));
    }

    /**
     * The letter goes to the NEW address and nowhere else. Sent to the current
     * one it would prove the visitor owns the mailbox they are leaving, which
     * says nothing about the one they are moving to — and a typo would become
     * the login.
     */
    protected function sendProof(string $value, string $token, ?string $code): void
    {
        $url = authFrontendMyConfirmAction::getTokenUrl($token);

        $message = new waMailMessage(_w('Confirm your new sign-in email'));
        $message->setBody(
            '<p>'.htmlspecialchars(_w('Open the link below to start signing in with this address.')).'</p>'.
            '<p><a href="'.htmlspecialchars($url).'">'.htmlspecialchars($url).'</a></p>'
        );
        $message->setTo($value);

        if (!$message->send()) {
            throw new waException('waMailMessage::send() returned false');
        }
    }

    /**
     * Whether another account already signs in with this address.
     *
     * Mirrors authEmailMethod::findByEmail(): the login is the primary address
     * (sort = 0) of an account that has a password and is not banned. A match
     * anywhere else in someone's address list is not a login and does not
     * collide — two people may well both list the same shared mailbox.
     */
    protected function isTaken(string $value): bool
    {
        $contact_id = (int)$this->contact->getId();

        $sql = "SELECT c.id FROM wa_contact c
                JOIN wa_contact_emails e ON c.id = e.contact_id
                WHERE e.email = s:email
                  AND e.sort = 0
                  AND c.id != i:id
                  AND c.password != ''
                  AND c.is_user > -1
                LIMIT 1";

        return (bool)(new waContactModel())
            ->query($sql, ['email' => $value, 'id' => $contact_id])
            ->fetchField('id');
    }
}
