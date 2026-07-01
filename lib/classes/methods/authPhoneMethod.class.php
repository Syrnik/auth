<?php

class authPhoneMethod extends authBuiltinMethod implements authMethod
{
    const AUTH_TYPE  = 'form';
    const HAS_RECOVERY = false;

    private const OTP_SESSION_KEY  = 'auth_phone_otp';
    private const OTP_TTL_SECONDS = 300;

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
        $contact = $this->findByPhone($phone);
        if (!$contact) {
            throw new authGuardException('Пользователь с таким номером не найден.');
        }

        $code = (string)random_int(100000, 999999);
        wa()->getStorage()->set(self::OTP_SESSION_KEY, [
            'contact_id' => (int)$contact['id'],
            'phone'      => $phone,
            'code'       => $code,
            'expires'    => time() + self::OTP_TTL_SECONDS,
        ]);

        $this->sendSms($phone, $code);

        throw new authMethodStepException(['show_code_field' => true]);
    }

    private function verifyOtp(string $phone, string $code): int
    {
        $stored = wa()->getStorage()->get(self::OTP_SESSION_KEY);

        if (!$stored
            || $stored['phone'] !== $phone
            || $stored['code'] !== $code
            || time() > $stored['expires']
        ) {
            throw new authMethodStepException([
                'show_code_field' => true,
                'error'           => 'Неверный или устаревший код.',
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
