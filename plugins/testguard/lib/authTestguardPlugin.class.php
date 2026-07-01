<?php

class authTestguardPlugin extends authPlugin implements authGuard
{
    public function checkSignup(array $form_data): void
    {
        $email = $form_data['email'] ?? '';
        if (str_ends_with(strtolower($email), '@example.com')) {
            throw new authGuardException('Регистрация с этим адресом запрещена.');
        }
    }

    public function checkLogin(int $contact_id): void
    {
        // This guard only blocks signup, not login.
    }
}
