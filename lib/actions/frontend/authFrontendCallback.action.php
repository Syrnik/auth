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
        try {
            $result = $method->handleCallback(waRequest::get());
        } catch (waException $e) {
            $this->renderError($e->getMessage());
            return;
        }

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
     * Renders login.html directly (not via authLoginFormAction), so it must
     * assign the same vars that template expects or Smarty warns/breaks.
     */
    private function renderError(string $message): void
    {
        $this->setLayout(new authFrontendLayout());
        $this->view->assign([
            'goal_url'        => (string) (wa()->getStorage()->get('auth_goal_url') ?? ''),
            'error'           => $message,
            'step_vars'       => [],
            'form_methods'    => authHelper::getFormMethods(),
            'oauth_providers' => authHelper::getOAuthProviders(),
            'csrf_token'      => authHelper::getCsrfToken(),
            'has_recovery'    => authHelper::hasRecovery(),
            'rememberme'      => authHelper::isRememberMeEnabled(),
            'has_registration' => authHelper::isRegistrationEnabled(),
            'register_url'    => authHelper::getRegisterUrl(),
            'recovery_url'    => authHelper::getRecoveryUrl(),
        ]);
        $this->setThemeTemplate('login.html');
    }
}
