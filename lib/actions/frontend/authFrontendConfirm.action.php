<?php

/**
 * confirm/<token>/ — the signup confirmation link — and confirm/ — resend of
 * that same link, decision 3 of docs/adr/005-value-confirmation.md: the
 * strict-login gate (authLoginConfirmGate) can end up blocking a contact
 * whose original 24h-TTL link expired unopened, and without a resend that is
 * a dead end. Also the only recourse for a contact that never went through
 * signup confirmation at all — created via OAuth or import — if it later
 * acquires a password and a login field the gate covers.
 */
class authFrontendConfirmAction extends waViewAction
{
    public function execute(): void
    {
        $token = waRequest::param('token', '', 'string');
        if ($token !== '') {
            $this->confirmByToken($token);
            return;
        }

        if (waRequest::method() === 'post') {
            $this->resend();
            return;
        }

        $this->showResendForm();
    }

    // -------------------------------------------------------------------------

    private function confirmByToken(string $token): void
    {
        $model = new authSignupConfirmModel();
        $row = $model->getValid($token);

        if (!$row) {
            $this->showError('Ссылка недействительна или устарела.');
            return;
        }

        $model->deleteById($row['id']);

        $contact = new waContact((int)$row['contact_id']);
        if (!$contact->exists()) {
            $this->showError('Аккаунт не найден.');
            return;
        }

        // Stamps the address the link was actually mailed to, not whatever
        // sits at sort = 0 right now — the visitor may have changed it since
        // registering, and stamping the current one would confirm an address
        // this very link never proved. Empty for a row issued before this
        // column existed; authContactStatus::confirmEmail('') simply finds
        // nothing to stamp on the contact and logs it, same as a genuinely
        // stale row would.
        authContactStatus::confirmEmail($contact->getId(), (string)($row['email'] ?? ''));

        wa()->getAuth()->auth(['id' => $contact->getId()]);
        wa()->event('login', $contact);

        $fallback = authHelper::localRedirectUrl(authConfig::get('redirect_after_register'), authHelper::getMyUrl());
        $redirect = authHelper::localRedirectUrl(wa()->getStorage()->get('auth_goal_url'), $fallback);

        wa()->getStorage()->del('auth_goal_url');
        wa()->getResponse()->redirect($redirect);
    }

    // -------------------------------------------------------------------------
    // Resend: confirm/, no token.
    // -------------------------------------------------------------------------

    private function showResendForm(string $error = ''): void
    {
        $this->setLayout(new authFrontendLayout());
        $this->view->assign(['error' => $error, 'sent' => false]);
        $this->setThemeTemplate('confirm.resend.html');
    }

    /**
     * Same throttle scope authFrontendRegisterAction uses for the same
     * resource (an outgoing email), and the same anti-enumeration contract
     * every authRecoveryProvider already upholds (docs/adr/004-recovery-channels.md):
     * the response never depends on whether $email resolved to an account,
     * or whether that account was already confirmed — only on the throttle,
     * checked and counted before any of that is even looked up.
     */
    private function resend(): void
    {
        $email = trim((string)waRequest::post('email', ''));

        $throttle_keys = ['ip' => waRequest::getIp(), 'login' => $email];
        $state = authThrottle::check('signup', $throttle_keys);
        if ($state->blocked) {
            $this->showResendForm(authThrottle::blockedMessage($state->retryAfter));
            return;
        }
        authThrottle::hit('signup', $throttle_keys);

        $contact = $this->findUnconfirmedContact($email);
        if ($contact) {
            $token = (new authSignupConfirmModel())->createToken($contact->getId(), $email);
            $confirm_url = wa()->getRouteUrl('auth/frontend/confirm', ['token' => $token], true);
            $this->sendConfirmEmail($email, $confirm_url);
        }

        $this->setLayout(new authFrontendLayout());
        $this->view->assign(['error' => '', 'sent' => true, 'email' => $email]);
        $this->setThemeTemplate('confirm.resend.html');
    }

    /**
     * The account $email signs in with, if it has one and its email is not
     * confirmed yet — null for every other case (malformed address, no
     * matching account, an account whose email is already proven), which the
     * caller treats identically to "found and resent": see resend()'s own
     * docblock on why that has to be the case.
     */
    private function findUnconfirmedContact(string $email): ?waContact
    {
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return null;
        }

        $sql = "SELECT c.id FROM wa_contact_emails e
                JOIN wa_contact c ON e.contact_id = c.id
                WHERE e.email = s:email AND e.sort = 0 AND c.is_user > -1
                LIMIT 1";
        $contact_id = (int)(new waContactModel())->query($sql, ['email' => $email])->fetchField('id');

        if ($contact_id <= 0 || authContactStatus::isPrimaryConfirmed('email', $contact_id)) {
            return null;
        }

        return new waContact($contact_id);
    }

    private function sendConfirmEmail(string $email, string $confirm_url): void
    {
        try {
            $m = new waMailMessage('Подтверждение регистрации');
            $m->setBody('<a href="' . htmlspecialchars($confirm_url) . '">Подтвердить регистрацию</a>');
            $m->setTo($email);
            $m->send();
        } catch (Exception $e) {
            waLog::log('auth register confirm email failed: ' . $e->getMessage(), 'auth.log');
        }
    }

    private function showError(string $message): void
    {
        $this->setLayout(new authFrontendLayout());
        $this->view->assign(['error' => $message]);
        $this->setThemeTemplate('register.confirm.html');
    }
}
