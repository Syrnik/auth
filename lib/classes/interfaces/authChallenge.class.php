<?php

interface authChallenge
{
    /**
     * Whether this factor is required for the given contact.
     */
    public function isRequired(int $contact_id): bool;

    /**
     * Verify the submitted challenge response.
     * Returns false to show the form again with an error.
     */
    public function verify(array $params): bool;

    /**
     * Unique string identifier for this challenge method.
     */
    public function getId(): string;
}
