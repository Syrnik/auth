<?php

interface authChallenge
{
    /**
     * Whether this factor is required for the given contact.
     */
    public function isRequired(int $contact_id): bool;

    /**
     * Verify the submitted challenge response.
     * Returns false for a wrong response — the form is shown again with
     * "Incorrect code.".
     *
     * Throw authChallengeThrottledException instead when the attempt is
     * refused without the response being checked (the plugin's own attempt
     * limit) — the form is shown again with the app's standard "try again in
     * N seconds" message. Check that limit before the response itself, so a
     * correct one submitted too early is refused as "wait", not as "wrong".
     *
     * @throws authChallengeThrottledException
     */
    public function verify(array $params): bool;

    /**
     * Unique string identifier for this challenge method.
     */
    public function getId(): string;
}
