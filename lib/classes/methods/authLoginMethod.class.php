<?php

class authLoginMethod extends authBuiltinMethod implements authMethod
{
    const AUTH_TYPE    = 'form';
    const HAS_RECOVERY = false;

    public function getId(): string
    {
        return 'login';
    }

    public function getName(): string
    {
        return 'Логин / пароль';
    }

    public function authenticate(array $params): ?int
    {
        $login    = trim((string)($params['login'] ?? ''));
        $password = (string)($params['password'] ?? '');

        if ($login === '' || $password === '') {
            throw new authGuardException('Введите логин и пароль.');
        }

        // When both email and login methods are enabled, the combined form submits here.
        // Try wa_contact.login first; fall back to email lookup when input looks like one.
        $contact = $this->findByLogin($login);
        if (!$contact && filter_var($login, FILTER_VALIDATE_EMAIL)) {
            $contact = $this->findByEmail($login);
        }

        if (!$contact) {
            throw new authGuardException('Пользователь не найден или неверный пароль.');
        }

        if (!waContact::verifyPasswordHash($password, $contact['password'])) {
            throw new authGuardException('Пользователь не найден или неверный пароль.');
        }

        return (int)$contact['id'];
    }

    public function handleCallback(array $params): authCallbackResult
    {
        throw new BadMethodCallException('Login method does not support OAuth callbacks.');
    }

    public function getCallbackUrl(): string
    {
        throw new BadMethodCallException('Login method does not have a callback URL.');
    }

    // -------------------------------------------------------------------------

    private function findByLogin(string $login): ?array
    {
        $model = new waContactModel();
        $sql = "SELECT * FROM wa_contact
                WHERE login = s:login
                  AND password != ''
                  AND is_user > -1
                ORDER BY id LIMIT 1";

        return $model->query($sql, ['login' => $login])->fetchAssoc() ?: null;
    }

    private function findByEmail(string $email): ?array
    {
        $model = new waContactModel();
        $sql = "SELECT c.* FROM wa_contact c
                JOIN wa_contact_emails e ON c.id = e.contact_id
                WHERE e.email = s:email
                  AND e.sort = 0
                  AND c.password != ''
                  AND c.is_user > -1
                ORDER BY c.id LIMIT 1";

        return $model->query($sql, ['email' => $email])->fetchAssoc() ?: null;
    }
}
