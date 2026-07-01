<?php

class authWaidMethod extends authBuiltinMethod implements authMethod
{
    const AUTH_TYPE    = 'oauth';
    const HAS_RECOVERY = false;

    public function getId(): string
    {
        return 'waid';
    }

    public function getName(): string
    {
        return 'Webasyst ID';
    }

    /**
     * Called when the login form is submitted with method=waid (POST fallback).
     * Normally the user clicks the WAID button which links to getCallbackUrl()
     * directly, bypassing authenticate() entirely.
     */
    public function authenticate(array $params): ?int
    {
        $adapter = $this->getAdapter();
        if (!$adapter) {
            throw new waException('Webasyst ID is not configured.');
        }
        $url = $adapter->getHealthyRedirectUri();
        if (!$url) {
            throw new waException('Webasyst ID auth endpoint is not available.');
        }
        wa()->getResponse()->redirect($url);
        return null;
    }

    /**
     * Handles auth/callback/waid/ route.
     *
     * First visit (no ?code): initiates WAID OAuth redirect.
     * Return visit (with ?code from WAID): exchanges code, resolves contact,
     * returns authCallbackResult for the auth pipeline (guards, challenges, login).
     */
    public function handleCallback(array $params): authCallbackResult
    {
        $adapter = $this->getAdapter();
        if (!$adapter) {
            throw new waException('Webasyst ID is not configured.');
        }

        if (empty($params['code'])) {
            // First leg: redirect user to WAID auth center.
            $url = $adapter->getHealthyRedirectUri();
            if (!$url) {
                throw new waException('Webasyst ID auth endpoint is not available.');
            }
            wa()->getResponse()->redirect($url);
            exit;
        }

        // Second leg: WAID returned with auth code — exchange and identify contact.
        $data = $adapter->processCallback();

        [$contact_id, $is_new] = authContactResolver::findOrCreate($data);
        return new authCallbackResult($contact_id, $is_new);
    }

    public function getCallbackUrl(): string
    {
        return authHelper::getCallbackUrl('waid');
    }

    // -------------------------------------------------------------------------

    private function getAdapter(): ?authWaidAdapter
    {
        $credentials = self::getCredentials();
        if (empty($credentials['app_id'])) {
            return null;
        }
        return new authWaidAdapter($credentials);
    }

    /**
     * Credentials configured in this app's own settings screen take priority.
     * Falls back to the framework's own Webasyst ID registration (wa-config/auth.php,
     * managed by the Site app) so installs that already had WAID working keep working
     * until the admin re-enters credentials here.
     */
    public static function getCredentials(): array
    {
        $credentials = authConfig::getAdapterCredentials('waid');
        if (!empty($credentials['app_id'])) {
            return $credentials;
        }
        return wa()->getAuthConfig()['adapters']['webasystID'] ?? [];
    }
}
