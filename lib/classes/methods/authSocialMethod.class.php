<?php

/**
 * Generic wrapper for any framework-level OAuth adapter (VK, Facebook, Google, ...).
 * Login is handled by the framework's own oauth.php dispatcher, routed to
 * authOAuthController via the standard app-hop mechanism (?app=auth&provider=...),
 * so getCallbackUrl() here returns the "start login" link, not our own callback route.
 */
class authSocialMethod extends authBuiltinMethod implements authMethod
{
    const AUTH_TYPE    = 'oauth';
    const HAS_RECOVERY = false;

    private string $id;
    private string $provider_id;

    public function __construct(string $id, string $provider_id)
    {
        $this->id          = $id;
        $this->provider_id = $provider_id;
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getName(): string
    {
        return wa()->getAuth($this->provider_id, [])->getName();
    }

    public function authenticate(array $params): ?int
    {
        wa()->getResponse()->redirect($this->getCallbackUrl());
        return null;
    }

    public function handleCallback(array $params): authCallbackResult
    {
        throw new BadMethodCallException('Handled via the framework oauth.php pipeline (authOAuthController), not the app callback route.');
    }

    public function getCallbackUrl(): string
    {
        $credentials = authConfig::getAdapterCredentials($this->id);
        if (empty($credentials['app_id'])) {
            throw new BadMethodCallException($this->id . ' is not configured.');
        }
        return wa()->getAuth($this->provider_id, $credentials)->url();
    }
}
