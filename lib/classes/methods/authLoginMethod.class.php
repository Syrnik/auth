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

        $contact = $this->findByLogin($login);
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

}
