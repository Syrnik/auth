<?php

interface authGuard
{
    /**
     * Check whether login should be allowed for this contact.
     * Throw authGuardException to block with a user-visible message.
     */
    public function checkLogin(int $contact_id): void;

    /**
     * Check whether signup should be allowed given the submitted form data.
     * Throw authGuardException to block with a user-visible message.
     */
    public function checkSignup(array $form_data): void;
}
