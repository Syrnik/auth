<?php

class authEmailMethod extends authBuiltinMethod implements authMethod
{
    const AUTH_TYPE  = 'form';
    const HAS_RECOVERY = true;

    public function getId(): string
    {
        return 'email';
    }

    public function getName(): string
    {
        return 'Email / пароль';
    }

    public function authenticate(array $params): ?int
    {
        $email    = trim((string)($params['email'] ?? $params['login'] ?? ''));
        $password = (string)($params['password'] ?? '');

        if ($email === '' || $password === '') {
            throw new authGuardException('Введите email и пароль.');
        }

        $contact = $this->findByEmail($email);
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
        throw new BadMethodCallException('Email method does not support OAuth callbacks.');
    }

    public function getCallbackUrl(): string
    {
        throw new BadMethodCallException('Email method does not have a callback URL.');
    }

    // -------------------------------------------------------------------------

    private function findByEmail(string $email): ?array
    {
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return null;
        }

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
