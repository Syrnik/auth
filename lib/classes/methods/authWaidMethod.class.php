<?php

class authWaidMethod extends authBuiltinMethod implements authMethod
{
    const AUTH_TYPE  = 'oauth';
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
     * Initiates WAID OAuth redirect.
     * WAID is always a GET link (button), so this is a fallback if called via POST.
     * Returns null after performing the redirect.
     */
    public function authenticate(array $params): ?int
    {
        $url = $this->getWaidUrl();
        wa()->getResponse()->redirect($url);
        return null;
    }

    /**
     * WAID callback is handled by the standard Webasyst oauth.php mechanism,
     * not through authFrontendCallbackAction.
     */
    public function handleCallback(array $params): authCallbackResult
    {
        throw new BadMethodCallException('WAID uses the standard oauth.php callback, not authFrontendCallbackAction.');
    }

    public function getCallbackUrl(): string
    {
        throw new BadMethodCallException('WAID callback URL is managed by the framework (oauth.php).');
    }

    /**
     * Returns the URL for the "Login with Webasyst ID" button.
     * This goes through the standard Webasyst WAID OAuth flow (oauth.php).
     */
    public function getWaidUrl(): string
    {
        $auth_config = wa()->getAuthConfig();
        $credentials = $auth_config['adapters']['webasystID'] ?? [];
        if (empty($credentials)) {
            return '';
        }
        $adapter = new waWebasystIDSiteAuth($credentials);
        $url = $adapter->getUrl();
        return $url;
    }
}
