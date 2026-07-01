<?php

interface authMethod
{
    /**
     * Authenticate user with given params.
     *
     * Returns int contact_id on success.
     * Returns null when OAuth redirect was already performed inside this method.
     * Throws authMethodStepException when another step is needed (OTP, magic link, etc).
     * Throws authGuardException to block with a user-visible message.
     */
    public function authenticate(array $params): ?int;

    /**
     * Handle OAuth callback. Returns authCallbackResult with contact_id and is_new flag.
     * Form/token methods may throw BadMethodCallException — they are never called by the controller.
     */
    public function handleCallback(array $params): authCallbackResult;

    /**
     * URL of the OAuth callback route for this method (auth/callback/{id}/).
     * Form/token methods may throw BadMethodCallException.
     */
    public function getCallbackUrl(): string;

    /**
     * Unique string identifier for this method (e.g. 'email', 'github').
     */
    public function getId(): string;
}
