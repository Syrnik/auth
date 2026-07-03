<?php

class authFrontendConfirmAction extends waViewAction
{
    public function execute(): void
    {
        $token = waRequest::param('token', '', 'string');
        if (!$token) {
            $this->showError('Некорректная ссылка подтверждения.');
            return;
        }

        $model = new waModel();
        $row = $model->query(
            "SELECT * FROM auth_signup_confirm WHERE token = s:token AND created_datetime > DATE_SUB(NOW(), INTERVAL 24 HOUR)",
            ['token' => $token]
        )->fetchAssoc();

        if (!$row) {
            $this->showError('Ссылка недействительна или устарела.');
            return;
        }

        $model->query("DELETE FROM auth_signup_confirm WHERE id = i:id", ['id' => $row['id']]);

        $contact = new waContact((int)$row['contact_id']);
        if (!$contact->exists()) {
            $this->showError('Аккаунт не найден.');
            return;
        }

        wa()->getAuth()->auth(['id' => $contact->getId()]);
        wa()->event('login', $contact);

        $fallback = authConfig::get('redirect_after_register') ?: authHelper::getMyUrl();
        $redirect = authHelper::localRedirectUrl(wa()->getStorage()->get('auth_goal_url'), $fallback);

        wa()->getStorage()->del('auth_goal_url');
        wa()->getResponse()->redirect($redirect);
    }

    private function showError(string $message): void
    {
        $this->setLayout(new authFrontendLayout());
        $this->view->assign(['error' => $message]);
        $this->setThemeTemplate('register.confirm.html');
    }
}
