<?php

/**
 * Channel half of the phone-confirmation flow — see authProfileConfirmEmailTrait
 * for the shape and authProfileConfirmableValueTrait for the part this pairs
 * with.
 */
trait authProfileConfirmPhoneTrait
{
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
     * save would apply. authProfileValues::normalizePhone() is exactly the
     * single-value edge of authProfileValues::preparePhones() below, so a
     * confirmation request and a save agree on what "the same number" means.
     */
    protected function normalize(string $value): string
    {
        return authProfileValues::normalizePhone($value);
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

    protected function stampConfirmed(string $value): bool
    {
        return authContactStatus::confirmPhone((int)$this->contact->getId(), $value);
    }

    /**
     * Keeps each number's confirmation status across a save, exactly as
     * authProfileValues::preparePhones() is documented to do — shared here so
     * the login twin (which edits one value through the same list-shaped
     * storage) does not carry its own copy of this.
     */
    protected function prepareList(&$list): void
    {
        authProfileValues::preparePhones($list, (int)$this->contact->getId());
    }
}
