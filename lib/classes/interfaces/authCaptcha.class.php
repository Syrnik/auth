<?php

interface authCaptcha
{
    /**
     * Render the captcha widget HTML (script + div).
     * Returns empty string if no widget output is needed.
     */
    public function renderWidget(): string;

    /**
     * Verify the captcha response from POST data.
     * Returns false to abort request processing with an error.
     */
    public function verifyCaptcha(array $post): bool;
}
