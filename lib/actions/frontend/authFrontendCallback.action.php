<?php

class authFrontendCallbackAction extends waViewAction
{
    public function execute(): void
    {
        // No login methods enabled for this site → auth is off here.
        if (!authConfig::isEnabled()) {
            throw new waException('Страница не найдена', 404);
        }

        $method_id = waRequest::param('method_id', '', 'string');
        $method = authPluginManager::get($method_id);

        if (!($method instanceof authMethod)) {
            throw new waException('Метод авторизации не найден.', 404);
        }

        // Handle OAuth callback. handleCallback() runs signup guards (via
        // authContactResolver) before creating any contact, so a blocked
        // signup surfaces here as a plain waException — nothing to roll back.
        //
        // A visitor who started this round from the profile's "Linked
        // accounts" section (my/link/<method_id>/) is inside authLinkIntent
        // by the time handleCallback() reaches authContactResolver::resolve()
        // — resolve() itself detects it and routes to authContactResolver::
        // link() instead of the normal find-or-create. authLinkException is
        // caught ahead of the plain waException below because renderError()
        // (login.html) is the wrong response to a failed link attempt.
        try {
            $result = $method->handleCallback(waRequest::get());
        } catch (authLinkException $e) {
            authLinkIntent::clear();
            wa()->getResponse()->redirect(authHelper::flashLinkResult($e->getMessage()));
            return;
        } catch (waException $e) {
            authLinkIntent::clear();
            $this->renderError($e->getMessage());
            return;
        }

        // A link round trip never creates a contact, never touches guards or
        // challenges, and never changes who is signed in — it only attaches
        // the identity that just came back to the contact that started it.
        // Checked by outcome, not by the marker's mere presence: an intent
        // that resolve() ignored (wrong source, expired, foreign session)
        // must fall through to the plain login below, not be reported as a
        // link that never happened.
        if (authLinkIntent::getOutcome() !== null) {
            authLinkIntent::clear();
            wa()->getStorage()->del('auth_goal_url');
            wa()->getResponse()->redirect(authHelper::flashLinkResult());
            return;
        }
        authLinkIntent::clear();

        $contact = new waContact($result->contact_id);

        if ($result->is_new) {
            wa()->event('signup', $contact);
        }

        // Login guards
        try {
            foreach (authPluginManager::getGuardsEnabled('login') as $guard) {
                $guard->checkLogin($result->contact_id);
            }
        } catch (authGuardException $e) {
            $this->renderError($e->getMessage());
            return;
        }

        // Challenge
        foreach (authPluginManager::getChallengeEnabled() as $challenge) {
            if ($challenge->isRequired($result->contact_id)) {
                wa()->getStorage()->set('auth_pending_id', $result->contact_id);
                wa()->getStorage()->set('auth_challenge', $challenge->getId());
                wa()->getResponse()->redirect(authHelper::getChallengeUrl());
                return;
            }
        }

        // Login
        $goal_url = wa()->getStorage()->get('auth_goal_url', '');
        wa()->getAuth()->auth(['id' => $result->contact_id]);
        wa()->getStorage()->del('auth_goal_url');
        wa()->event('login', $contact);

        $fallback = authHelper::localRedirectUrl(authConfig::get('redirect_after_login'), '/');
        $redirect = authHelper::localRedirectUrl($goal_url, $fallback);
        wa()->getResponse()->redirect($redirect);
    }

    /**
     * Renders login.html directly (not via authLoginFormAction), so it reuses
     * the same template-variable set via authHelper::loginViewData().
     */
    private function renderError(string $message): void
    {
        $goal_url = (string) (wa()->getStorage()->get('auth_goal_url') ?? '');
        $this->setLayout(new authFrontendLayout());
        $this->view->assign(authHelper::loginViewData($goal_url, $message));
        $this->setThemeTemplate('login.html');
    }
}
