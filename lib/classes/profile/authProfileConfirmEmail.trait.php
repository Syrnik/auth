<?php

/**
 * Channel half of the email-confirmation flow, shared by the two sections
 * that ever prove an email address: authProfileSectionLoginEmail (a new
 * login value, not yet stored) and authProfileSectionEmail (an already-
 * stored secondary address, confirmed independently of the login one). Used
 * together with authProfileConfirmableValueTrait, which supplies the
 * value-agnostic half (issue + send + rollback).
 */
trait authProfileConfirmEmailTrait
{
    public function getConfirmableField(): string
    {
        return 'email';
    }

    public function usesCode(): bool
    {
        return false;
    }

    /**
     * Mailbox names are case-sensitive in the standard and never in
     * practice; the domain never is. Comparing as typed would let the same
     * address be "new" because of a capital letter.
     */
    protected function normalize(string $value): string
    {
        return mb_strtolower(trim($value));
    }

    /**
     * The letter goes to the address being proven and nowhere else. Sent to
     * a different one it would prove ownership of the wrong mailbox, and a
     * typo would pass as confirmed.
     */
    protected function sendProof(string $value, string $token, ?string $code): void
    {
        $url = authFrontendMyConfirmAction::getTokenUrl($token);

        $message = new waMailMessage($this->confirmEmailSubject());
        $message->setBody(
            '<p>'.htmlspecialchars($this->confirmEmailIntro()).'</p>'.
            '<p><a href="'.htmlspecialchars($url).'">'.htmlspecialchars($url).'</a></p>'
        );
        $message->setTo($value);

        if (!$message->send()) {
            throw new waException('waMailMessage::send() returned false');
        }
    }

    /**
     * Stamps the address confirmed once the token/code has come back —
     * called from authProfileSectionLogin::applyConfirmedValue() (after the
     * value is written) and from the plain section's applyConfirmedValue()
     * (the value was already there). See authContactStatus.
     */
    protected function stampConfirmed(string $value): bool
    {
        return authContactStatus::confirmEmail((int)$this->contact->getId(), $value);
    }

    /**
     * Overridable per section: the login twin keeps its existing wording,
     * which is specifically about signing in; the plain section's wording
     * must not imply that, since confirming a secondary address changes
     * nothing about how this contact signs in.
     */
    protected function confirmEmailSubject(): string
    {
        return _w('Confirm your email address');
    }

    protected function confirmEmailIntro(): string
    {
        return _w('Open the link below to confirm this address.');
    }
}
