<?php

class authFrontendChallengeAction extends waViewAction
{
    use authJsonResponseTrait;

    public function execute(): void
    {
        $pending_id   = wa()->getStorage()->get('auth_pending_id');
        $challenge_id = wa()->getStorage()->get('auth_challenge');

        if (!$pending_id || !$challenge_id) {
            $this->redirectToLogin();
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
            $this->redirectToLogin();
            return;
        }

        if (waRequest::method() === 'post') {
            $verified = $challenge->verify(waRequest::post());

            if (!$verified) {
                // JSON path returns only the error message, the plugin's form
                // stays as rendered — fine for a stateless code input like
                // totp. A challenge needing a fresh form per attempt should
                // use {status: 'step', html: ...} instead (see auth.js).
                if (waRequest::isXMLHttpRequest()) {
                    $this->sendJson(['status' => 'error', 'error' => 'Неверный код.']);
                    return;
                }
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

            if (waRequest::isXMLHttpRequest()) {
                $this->sendJson(['status' => 'ok', 'redirect' => $redirect]);
                return;
            }
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

    /**
     * No (or no longer valid) pending challenge session — send back to login.
     * 'ok' is a bit of a stretch for a redirect that isn't a login success,
     * but auth.js already follows it (see the 'ok'/'challenge' branch), and a
     * dedicated status would mean touching the shared JS dispatcher for no
     * user-visible gain.
     */
    private function redirectToLogin(): void
    {
        $url = authHelper::getLoginUrl();
        if (waRequest::isXMLHttpRequest()) {
            $this->sendJson(['status' => 'ok', 'redirect' => $url]);
            return;
        }
        wa()->getResponse()->redirect($url);
    }
}
