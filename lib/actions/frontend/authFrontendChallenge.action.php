<?php

class authFrontendChallengeAction extends waViewAction
{
    public function execute(): void
    {
        $pending_id   = wa()->getStorage()->get('auth_pending_id');
        $challenge_id = wa()->getStorage()->get('auth_challenge');

        if (!$pending_id || !$challenge_id) {
            wa()->getResponse()->redirect(authHelper::getLoginUrl());
            return;
        }

        $challenge = null;
        foreach (authPluginManager::getChallengeEnabled() as $c) {
            if ($c->getId() === $challenge_id) {
                $challenge = $c;
                break;
            }
        }

        if (!$challenge) {
            wa()->getStorage()->del('auth_pending_id');
            wa()->getStorage()->del('auth_challenge');
            wa()->getResponse()->redirect(authHelper::getLoginUrl());
            return;
        }

        if (waRequest::method() === 'post') {
            $verified = $challenge->verify(waRequest::post());

            if (!$verified) {
                $this->setLayout(new authFrontendLayout());
                $this->view->assign([
                    'error'              => 'Неверный код.',
                    'challenge_form_html' => method_exists($challenge, 'getFormHtml') ? $challenge->getFormHtml() : '',
                ]);
                $this->setThemeTemplate('challenge.html');
                return;
            }

            $contact = new waContact((int)$pending_id);
            wa()->getAuth()->auth(['id' => (int)$pending_id]);
            wa()->getStorage()->del('auth_pending_id');
            wa()->getStorage()->del('auth_challenge');
            wa()->event('login', $contact);

            $fallback = authHelper::localRedirectUrl(authConfig::get('redirect_after_login'), '/');
            $redirect = authHelper::localRedirectUrl(wa()->getStorage()->get('auth_goal_url'), $fallback);
            wa()->getStorage()->del('auth_goal_url');
            wa()->getResponse()->redirect($redirect);
            return;
        }

        $this->setLayout(new authFrontendLayout());
        $this->view->assign([
            'error'              => '',
            'challenge_form_html' => method_exists($challenge, 'getFormHtml') ? $challenge->getFormHtml() : '',
        ]);
        $this->setThemeTemplate('challenge.html');
    }
}
