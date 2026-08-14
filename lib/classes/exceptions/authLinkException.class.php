<?php

/**
 * Blocks an account-linking round trip (my/link/<method_id>/ → callback) with
 * a user-visible message. Deliberately not authGuardException: that one is
 * caught by the existing login/signup handling (renderError() → login.html,
 * or displayError() → echo+exit), neither of which is the right response when
 * the visitor was already signed in and only tried to add a login method.
 */
class authLinkException extends waException
{
    public function __construct(string $message)
    {
        parent::__construct($message, 409);
    }
}
