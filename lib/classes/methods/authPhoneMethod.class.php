<?php

class authPhoneMethod extends authBuiltinMethod implements authMethod
{
    const AUTH_TYPE  = 'form';
    const HAS_RECOVERY = false;

    private const OTP_SESSION_KEY        = 'auth_phone_otp';
    private const OTP_TTL_SECONDS        = 300;
    private const RESEND_COOLDOWN_SECONDS = 60;
    private const MAX_SENDS              = 5;
    private const MAX_ATTEMPTS           = 5;

    public function getId(): string
    {
        return 'phone';
    }

    public function getName(): string
    {
        return 'Телефон (OTP)';
    }

    public function authenticate(array $params): ?int
    {
        $phone = trim((string)($params['phone'] ?? ''));

        if ($phone === '') {
            throw new authGuardException('Введите номер телефона.');
        }

        $otp_code = trim((string)($params['otp_code'] ?? ''));

        if ($otp_code !== '') {
            return $this->verifyOtp($phone, $otp_code);
        }

        return $this->sendOtp($phone);
    }

    public function handleCallback(array $params): authCallbackResult
    {
        throw new BadMethodCallException('Phone method does not support OAuth callbacks.');
    }

    public function getCallbackUrl(): string
    {
        throw new BadMethodCallException('Phone method does not have a callback URL.');
    }

    // -------------------------------------------------------------------------

    private function sendOtp(string $phone): ?int
    {
        $now    = time();
        $stored = wa()->getStorage()->get(self::OTP_SESSION_KEY);
        $active = $stored && $stored['phone'] === $phone && $now < ($stored['expires'] ?? 0);

        // Throttle resends for the same number so the SMS channel can't be spammed
        // (each message costs money) and the code can't be endlessly re-rolled.
        if ($active) {
            $since_last = $now - ($stored['last_sent'] ?? 0);
            if ($since_last < self::RESEND_COOLDOWN_SECONDS) {
                throw new authMethodStepException([
                    'show_code_field' => true,
                    'error' => sprintf('Код уже отправлен. Повторная отправка через %d сек.', self::RESEND_COOLDOWN_SECONDS - $since_last),
                ]);
            }
            if (($stored['sends'] ?? 0) >= self::MAX_SENDS) {
                wa()->getStorage()->del(self::OTP_SESSION_KEY);
                throw new authGuardException('Слишком много запросов кода. Попробуйте позже.');
            }
        }

        $contact = $this->findByPhone($phone);
        if (!$contact) {
            throw new authGuardException('Пользователь с таким номером не найден.');
        }

        $code = (string)random_int(100000, 999999);
        wa()->getStorage()->set(self::OTP_SESSION_KEY, [
            'contact_id' => (int)$contact['id'],
            'phone'      => $phone,
            // Never keep the plain code in the session store.
            'hash'       => password_hash($code, PASSWORD_DEFAULT),
            'expires'    => $now + self::OTP_TTL_SECONDS,
            'attempts'   => 0,
            'sends'      => ($active ? (int)$stored['sends'] : 0) + 1,
            'last_sent'  => $now,
        ]);

        $this->sendSms($phone, $code);

        throw new authMethodStepException(['show_code_field' => true]);
    }

    private function verifyOtp(string $phone, string $code): int
    {
        $stored = wa()->getStorage()->get(self::OTP_SESSION_KEY);

        if (!$stored || $stored['phone'] !== $phone || time() > ($stored['expires'] ?? 0)) {
            wa()->getStorage()->del(self::OTP_SESSION_KEY);
            throw new authMethodStepException([
                'show_code_field' => true,
                'error'           => 'Код устарел. Запросите новый.',
            ]);
        }

        // Cap guesses so a 6-digit code can't be brute-forced within its TTL.
        if (($stored['attempts'] ?? 0) >= self::MAX_ATTEMPTS) {
            wa()->getStorage()->del(self::OTP_SESSION_KEY);
            throw new authGuardException('Слишком много неверных попыток. Запросите новый код.');
        }

        if (!password_verify($code, (string)($stored['hash'] ?? ''))) {
            $stored['attempts'] = (int)($stored['attempts'] ?? 0) + 1;
            wa()->getStorage()->set(self::OTP_SESSION_KEY, $stored);
            $left = self::MAX_ATTEMPTS - $stored['attempts'];
            throw new authMethodStepException([
                'show_code_field' => true,
                'error'           => $left > 0
                    ? sprintf('Неверный код. Осталось попыток: %d.', $left)
                    : 'Неверный код.',
            ]);
        }

        wa()->getStorage()->del(self::OTP_SESSION_KEY);
        return (int)$stored['contact_id'];
    }

    private function findByPhone(string $phone): ?array
    {
        $model = new waContactModel();
        $sql = "SELECT c.* FROM wa_contact c
                JOIN wa_contact_data d ON c.id = d.contact_id AND d.field = 'phone'
                WHERE d.value = s:phone
                  AND d.sort = 0
                  AND c.password != ''
                  AND c.is_user > -1
                ORDER BY c.id LIMIT 1";

        return $model->query($sql, ['phone' => $phone])->fetchAssoc() ?: null;
    }

    private function sendSms(string $phone, string $code): void
    {
        // SMS sending is handled by the system's SMS plugins.
        // Use wa()-based SMS sending or throw if not configured.
        $sms = new waSMS();
        $sms->send($phone, "Ваш код подтверждения: {$code}");
    }
}
