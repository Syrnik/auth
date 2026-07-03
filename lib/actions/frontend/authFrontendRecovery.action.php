<?php

class authFrontendRecoveryAction extends waViewAction
{
    public function execute(): void
    {
        if (!authConfig::get('recovery_enabled') || !authHelper::hasRecovery()) {
            throw new waException('Страница не найдена', 404);
        }

        $token = waRequest::param('token', '', 'string');

        if ($token) {
            $this->handleTokenStep($token);
        } elseif (waRequest::method() === 'post') {
            $this->handleEmailPost();
        } else {
            $this->setLayout(new authFrontendLayout());
            $this->view->assign(['error' => '', 'sent' => false, 'token' => '']);
            $this->setThemeTemplate('recovery.html');
        }
    }

    // GET/POST without token: "forgot password" form
    private function handleEmailPost(): void
    {
        $email = trim((string)waRequest::post('email', ''));
        if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->setLayout(new authFrontendLayout());
            $this->view->assign(['error' => 'Введите корректный email.', 'sent' => false, 'token' => '']);
            $this->setThemeTemplate('recovery.html');
            return;
        }

        // Find contact
        $model = new waContactModel();
        $sql = "SELECT c.id FROM wa_contact c
                JOIN wa_contact_emails e ON c.id = e.contact_id
                WHERE e.email = s:email AND e.sort = 0 AND c.password != '' AND c.is_user > -1
                ORDER BY c.id LIMIT 1";
        $contact_row = $model->query($sql, ['email' => $email])->fetchAssoc();

        // Always show "sent" to prevent email enumeration
        if ($contact_row) {
            $token = (new authPasswordRecoveryModel())->createToken((int)$contact_row['id']);

            $recovery_url = wa()->getRouteUrl('auth/frontend/recovery', ['token' => $token], true);
            $this->sendRecoveryEmail($email, $recovery_url);
        }

        $this->setLayout(new authFrontendLayout());
        $this->view->assign(['error' => '', 'sent' => true, 'token' => '']);
        $this->setThemeTemplate('recovery.html');
    }

    // GET with token: show change-password form
    // POST with token: validate and change password
    private function handleTokenStep(string $token): void
    {
        $recovery_model = new authPasswordRecoveryModel();
        $stored = $recovery_model->getValid($token);

        if (!$stored) {
            $this->setLayout(new authFrontendLayout());
            $this->view->assign(['error' => 'Ссылка недействительна или устарела.', 'token' => '', 'password_changed' => false]);
            $this->setThemeTemplate('recovery.html');
            return;
        }

        if (waRequest::method() === 'post') {
            $password = (string)waRequest::post('password', '');
            $password_confirm = (string)waRequest::post('password_confirm', '');

            if (!$password) {
                $this->setLayout(new authFrontendLayout());
                $this->view->assign(['error' => 'Введите новый пароль.', 'token' => $token, 'password_changed' => false]);
                $this->setThemeTemplate('recovery.html');
                return;
            }
            if ($password !== $password_confirm) {
                $this->setLayout(new authFrontendLayout());
                $this->view->assign(['error' => 'Пароли не совпадают.', 'token' => $token, 'password_changed' => false]);
                $this->setThemeTemplate('recovery.html');
                return;
            }

            $contact = new waContact((int)$stored['contact_id']);
            if (!$contact->exists()) {
                $this->setLayout(new authFrontendLayout());
                $this->view->assign(['error' => 'Аккаунт не найден.', 'token' => '', 'password_changed' => false]);
                $this->setThemeTemplate('recovery.html');
                return;
            }

            $contact['password'] = $password;
            $contact->save();

            // Invalidate this recovery token so the link can't be replayed.
            $recovery_model->deleteByToken($token);

            // Other active sessions of this contact are invalidated by the
            // framework itself: the session/remember-me token is derived from
            // the password hash (waAuth::getToken), so once the password changes,
            // every other session fails waAuthUser's periodic token re-check and
            // every stale auth_token cookie stops matching. Re-authing here rotates
            // the current session onto the new token so this browser stays logged in.
            wa()->getAuth()->auth(['id' => $contact->getId()]);
            wa()->event('login', $contact);

            $redirect = authHelper::localRedirectUrl(authConfig::get('redirect_after_login'), authHelper::getMyUrl());
            wa()->getResponse()->redirect($redirect);
            return;
        }

        $this->setLayout(new authFrontendLayout());
        $this->view->assign(['error' => '', 'token' => $token, 'password_changed' => false]);
        $this->setThemeTemplate('recovery.html');
    }

    private function sendRecoveryEmail(string $email, string $url): void
    {
        try {
            $m = new waMailMessage('Восстановление пароля');
            $m->setBody('<a href="' . htmlspecialchars($url) . '">Изменить пароль</a>');
            $m->setTo($email);
            $m->send();
        } catch (Exception $e) {
            waLog::log('auth recovery email failed: ' . $e->getMessage(), 'auth.log');
        }
    }
}
