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

        wa()->getAuth()->auth(['id' => $contact->getId()]);
        wa()->event('login', $contact);

        $fallback = authHelper::localRedirectUrl(authConfig::get('redirect_after_register'), authHelper::getMyUrl());
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
