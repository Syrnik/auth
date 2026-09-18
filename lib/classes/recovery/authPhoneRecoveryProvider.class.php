<?php

/**
 * Built-in recovery channel for the 'phone' identifier shape: a six-digit
 * code texted to the number, typed back on the same page. Registered by
 * authPluginManager under id 'phone' — no different, from authRecovery's
 * point of view, than a third-party authRecoveryProvider plugin would be.
 *
 * See docs/adr/004-recovery-channels.md. This is the provider decision 5
 * exists for: needsCode() === true means the visible outcome of start() is a
 * code-entry step, which a naive "pending row exists → code step, otherwise
 * → sent page" implementation would use to answer "does this number belong
 * to an account" instantly, for any number. Every call to start() therefore
 * issues a row and returns a code-entry handle unconditionally — real number
 * or not — and only the SMS send is skipped when there is nobody to send it
 * to. verifyCode()/complete() never look at contact_id before deciding how to
 * fail, only at the row itself, so a decoy fails a submitted code exactly
 * like a wrong guess against a real one.
 *
 * This has nothing to do with authPhoneMethod, which is the OTP *login* for
 * a passwordless phone-only contact — see decision 1 of the ADR. This
 * provider resets the password of whatever account the phone number belongs
 * to, whether or not that account signs in by phone at all.
 */
class authPhoneRecoveryProvider implements authRecoveryProvider
{
    // Same order of magnitude as authPhoneMethod::OTP_TTL_SECONDS — a
    // recovery code is exactly as short-lived as a login one.
    private const CODE_TTL_SECONDS = 300;

    public function getId(): string
    {
        return 'phone';
    }

    public function claims(string $identifier): bool
    {
        $identifier = trim($identifier);
        return $identifier !== ''
            && preg_match('/\d/', $identifier) === 1
            && (new waPhoneNumberValidator())->isValid($identifier);
    }

    public function start(string $identifier): authRecoveryHandle
    {
        $phone           = authProfileValues::normalizePhone($identifier);
        $identifier_hash = hash('sha256', $phone);
        $keys            = ['ip' => waRequest::getIp(), 'login' => $phone];

        // Paid-resource throttle (SMS), the same scope authPhoneMethod::
        // sendOtp() uses — checked before this number is ever resolved to a
        // contact, and keyed on the typed number alone, so a real number's
        // send cap can never be told apart from a made-up one's by how soon
        // repeats get refused (decision 5 of the ADR): both take the same
        // number of requests to trip, and both get the exact same refusal
        // once tripped. Not authPhoneMethod's own budget by coincidence:
        // sharing scope 'otp_send' is what stops recovery from being a
        // second, unmetered way to spend the same paid resource (postanovka's
        // point 1).
        $state = authThrottle::check('otp_send', $keys);
        if ($state->blocked) {
            throw new authRecoveryThrottledException($state->retryAfter);
        }
        authThrottle::hit('otp_send', $keys);

        $contact_id = self::findContactId($phone);

        // Issued unconditionally past this point — a real number and an
        // unknown one take the same path. A decoy row is a real row with
        // contact_id = 0 and a real, never-sent code
        // (authPasswordRecoveryModel::issue()), not a special case handled
        // separately.
        $model  = new authPasswordRecoveryModel();
        $issued = $model->issue($contact_id ?? 0, $this->getId(), $identifier_hash, true, self::CODE_TTL_SECONDS);

        // The only branch on whether this number is real, and it is
        // invisible to whoever is submitting the form either way: they get
        // the same code-entry response regardless, they just don't receive
        // an SMS (nor would they, if it were a stranger's real number).
        if ($contact_id !== null) {
            $this->sendSms($phone, (string)$issued['code']);
        }

        return new authRecoveryHandle($this->getId(), $issued['token']);
    }

    public function needsCode(): bool
    {
        return true;
    }

    public function verifyCode(authRecoveryHandle $handle, string $code): bool
    {
        $model = new authPasswordRecoveryModel();
        $row   = $model->getValid($handle->token);
        if (!$row || (string)$row['channel'] !== $this->getId()) {
            // Expired/consumed/foreign token behaves like a wrong code: no
            // distinct message that would tell "this token never existed"
            // apart from "you ran out of guesses".
            return false;
        }

        return $model->verifyCode($row, $code);
    }

    public function complete(authRecoveryHandle $handle): ?int
    {
        $model = new authPasswordRecoveryModel();
        $row   = $model->getValid($handle->token);
        if (!$row || (string)$row['channel'] !== $this->getId()) {
            return null;
        }

        $contact_id = (int)$row['contact_id'];
        $model->deleteByToken($handle->token);

        return $contact_id > 0 ? $contact_id : null;
    }

    // -------------------------------------------------------------------------

    private static function findContactId(string $phone): ?int
    {
        $model = new waContactModel();
        $sql = "SELECT c.id FROM wa_contact c
                JOIN wa_contact_data d ON c.id = d.contact_id AND d.field = 'phone'
                WHERE d.value = s:phone
                  AND d.sort = 0
                  AND c.password != ''
                  AND c.is_user > -1
                ORDER BY c.id LIMIT 1";

        $row = $model->query($sql, ['phone' => $phone])->fetchAssoc();
        return $row ? (int)$row['id'] : null;
    }

    private function sendSms(string $phone, string $code): void
    {
        try {
            $sms = new waSMS();
            $sms->send($phone, sprintf(_w('Your password recovery code: %s'), $code));
        } catch (Exception $e) {
            waLog::log('auth recovery sms failed: ' . $e->getMessage(), 'auth.log');
        }
    }
}
