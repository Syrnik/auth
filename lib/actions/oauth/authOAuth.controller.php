<?php

/**
 * Handles oauth.php?app=auth&provider=<id> for every framework-level OAuth
 * adapter except Webasyst ID (which the framework always processes itself and
 * routes through our own auth/callback/waid/ route instead, see authWaidMethod).
 *
 * The framework dispatches both legs of the OAuth flow here (initial redirect
 * and the provider's return visit with ?code=...), see waOAuthController::execute().
 * Overriding afterAuth() lets guards/challenges run before the session is created,
 * same as for every other method in this app.
 */
class authOAuthController extends waOAuthController
{
    protected function getAuthAdapter($provider)
    {
        $system_adapters = authPluginManager::getSystemAdapters();
        if ($provider === 'waid' || !isset($system_adapters[$provider])) {
            throw new waException('Unknown auth provider', 404);
        }
        if (!in_array($provider, authConfig::getLoginMethods(), true)) {
            throw new waException('Auth method is disabled', 404);
        }

        $credentials = authConfig::getAdapterCredentials($provider);
        if (empty($credentials['app_id'])) {
            throw new waException('Auth provider is not configured', 404);
        }

        return wa()->getAuth($provider, $credentials);
    }

    protected function afterAuth($data)
    {
        // authContactResolver runs signup guards against the raw OAuth data
        // before creating anything, so a blocked signup never touches the DB.
        try {
            [$contact_id, $is_new] = authContactResolver::resolve($data);
        } catch (authGuardException $e) {
            $this->displayError($e->getMessage());
        }

        if ($is_new) {
            wa()->event('signup', new waContact($contact_id));
        }

        try {
            foreach (authPluginManager::getGuardsEnabled('login') as $guard) {
                $guard->checkLogin($contact_id);
            }
        } catch (authGuardException $e) {
            $this->displayError($e->getMessage());
        }

        foreach (authPluginManager::getChallengeEnabled() as $challenge) {
            if ($challenge->isRequired($contact_id)) {
                wa()->getStorage()->set('auth_pending_id', $contact_id);
                wa()->getStorage()->set('auth_challenge', $challenge->getId());
                $this->cleanup();
                wa()->getResponse()->redirect(authHelper::getChallengeUrl());
            }
        }

        $contact = new waContact($contact_id);
        wa()->getAuth()->auth(['id' => $contact_id]);
        wa()->event('login', $contact);
        return $contact;
    }
}
