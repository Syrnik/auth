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
        // displayError() ends the request (echo + exit); the explicit return
        // keeps $contact_id/$is_new from being read uninitialized if a future
        // refactor ever makes displayError() fall through instead of exiting.
        //
        // A visitor who started this round from the profile's "Linked
        // accounts" section (my/link/<method_id>/) is caught by authLinkIntent
        // inside resolve() itself, which routes to authContactResolver::link()
        // instead of find-or-create. authLinkException is caught ahead of
        // authGuardException — displayError() is the wrong response to a
        // failed link attempt, and cleanup() has to run before the redirect
        // since waOAuthController::execute() only calls it after afterAuth()
        // returns, and redirect() exits before that happens.
        try {
            [$contact_id, $is_new] = authContactResolver::resolve($data);
        } catch (authLinkException $e) {
            authLinkIntent::clear();
            $this->cleanup();
            wa()->getResponse()->redirect(authHelper::flashLinkResult($e->getMessage()));
            return null;
        } catch (authGuardException $e) {
            authLinkIntent::clear();
            $this->displayError($e->getMessage());
            return null;
        }

        // Link round trip: attach the identity and go back to the profile.
        // Never creates a contact, never runs guards/challenges, never
        // changes who is signed in. Checked by outcome, not by the marker's
        // mere presence — an intent resolve() ignored (wrong source, expired,
        // foreign session) must fall through to the plain login below.
        $outcome = authLinkIntent::getOutcome();
        if ($outcome !== null) {
            authLinkIntent::clear();
            $this->cleanup();
            // 'already_linked' is a no-op re-link (back button, double click) —
            // nothing changed, so it earns no entry, same as unlink logs only
            // on an actual write (authFrontendMySaveController::logSave()).
            if ($outcome === 'linked') {
                $this->logAction(
                    'my_profile_edit',
                    ['section' => authProfileSectionLinkedAccounts::ID],
                    null,
                    $contact_id
                );
            }
            wa()->getResponse()->redirect(authHelper::flashLinkResult());
            return null;
        }
        authLinkIntent::clear();

        if ($is_new) {
            wa()->event('signup', new waContact($contact_id));
        }

        try {
            foreach (authPluginManager::getGuardsEnabled('login') as $guard) {
                $guard->checkLogin($contact_id);
            }
        } catch (authGuardException $e) {
            $this->displayError($e->getMessage());
            return null;
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
