<?php

class authFrontendRegisterAction extends waViewAction
{
    use authJsonResponseTrait;

    public function execute(): void
    {
        if (!authHelper::isRegistrationEnabled()) {
            throw new waException('Регистрация отключена.', 404);
        }

        if (waRequest::method() === 'post') {
            $this->handlePost();
        } else {
            $this->showForm();
        }
    }

    private function showForm(array $errors = [], array $data = []): void
    {
        $this->setLayout(new authFrontendLayout());
        $this->view->assign([
            'signup_fields' => authConfig::get('signup_fields', ['firstname', 'email', 'password']),
            'errors'        => $errors,
            'data'          => $data,
            'csrf_token'    => authHelper::getCsrfToken(),
            'login_url'     => authHelper::getLoginUrl(),
            'captcha_widget' => authHelper::getCaptchaWidget(),
        ]);
        $this->setThemeTemplate('register.html');
    }

    private function handlePost(): void
    {
        $post = waRequest::post();
        $fields = authConfig::get('signup_fields', ['firstname', 'email', 'password']);
        $errors = [];

        // Captcha: stop immediately on failure, same as authLoginController —
        // a bad captcha shouldn't still spend guard checks (rate limits, etc.)
        // or have its error overwritten by a later guard block.
        $captcha = authPluginManager::getCaptchaPlugin();
        if ($captcha && !$captcha->verifyCaptcha($post)) {
            $this->showForm(['captcha' => 'Неверный код капчи.'], $post);
            return;
        }

        // Guards: a guard block is final, so show it alone and stop —
        // field validation makes no sense for a signup that cannot proceed
        try {
            foreach (authPluginManager::getGuardsEnabled('signup') as $guard) {
                $guard->checkSignup($post);
            }
        } catch (authGuardException $e) {
            $this->showForm(['guard' => $e->getMessage()], $post);
            return;
        }

        // Basic field validation
        if (in_array('email', $fields) && empty($post['email'])) {
            $errors['email'] = 'Email обязателен.';
        } elseif (in_array('email', $fields) && !filter_var($post['email'], FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'Некорректный email.';
        } elseif (in_array('email', $fields) && $this->emailTaken($post['email'])) {
            // Block a duplicate up front: two accounts sharing an email make the
            // email login method ambiguous (it takes the lowest id), so the second
            // registrant could never log in by email anyway.
            $errors['email'] = 'Этот email уже зарегистрирован.';
        }
        if (in_array('password', $fields) && empty($post['password'])) {
            $errors['password'] = 'Пароль обязателен.';
        }

        if ($errors) {
            $this->showForm($errors, $post);
            return;
        }

        // Create contact
        $contact = new waContact();
        foreach ($fields as $field) {
            if (isset($post[$field]) && $field !== 'password') {
                $contact[$field] = $post[$field];
            }
        }
        if (in_array('password', $fields) && !empty($post['password'])) {
            $contact['password'] = $post['password'];
        }
        $contact['is_user'] = 1;
        $contact->save();

        if ($contact->getId() <= 0) {
            $this->showForm(['general' => 'Ошибка при создании аккаунта.'], $post);
            return;
        }

        wa()->event('signup', $contact);

        // Confirm by email
        if (authConfig::get('signup_confirm') && in_array('email', $fields)) {
            $token = (new authSignupConfirmModel())->createToken($contact->getId());

            $confirm_url = wa()->getRouteUrl(
                'auth/frontend/confirm',
                ['token' => $token],
                true
            );
            $this->sendConfirmEmail($contact->get('email', 'default'), $confirm_url);

            if (waRequest::isXMLHttpRequest()) {
                $this->sendJson(['status' => 'confirm_required']);
            } else {
                $this->setLayout(new authFrontendLayout());
                $this->view->assign(['email' => $contact->get('email', 'default')]);
                $this->setThemeTemplate('register.confirm.html');
            }
            return;
        }

        // Auto-login
        wa()->getAuth()->auth(['id' => $contact->getId()]);
        wa()->event('login', $contact);
        $redirect = authHelper::localRedirectUrl(authConfig::get('redirect_after_register'), authHelper::getMyUrl());

        if (waRequest::isXMLHttpRequest()) {
            $this->sendJson(['status' => 'ok', 'redirect' => $redirect]);
        } else {
            wa()->getResponse()->redirect($redirect);
        }
    }

    private function emailTaken(string $email): bool
    {
        $model = new waContactModel();
        return (bool) $model->query(
            "SELECT c.id FROM wa_contact_emails e
             JOIN wa_contact c ON e.contact_id = c.id
             WHERE e.email = s:email AND c.is_user > -1
             LIMIT 1",
            ['email' => $email]
        )->fetchField('id');
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

}
