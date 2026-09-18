<?php

/**
 * Built-in recovery channel for the 'email' identifier shape: a link mailed
 * to the address, valid until it expires or is used. Registered by
 * authPluginManager under id 'email' — no different, from authRecovery's
 * point of view, than a third-party authRecoveryProvider plugin would be.
 *
 * See docs/adr/004-recovery-channels.md. Anti-enumeration here needs no
 * decoy row (contrast authPhoneRecoveryProvider): needsCode() is false, so
 * the core always renders the same generic "sent" page regardless of what
 * start() actually did — a row is created and a mail sent only when the
 * address resolves, exactly like the email-only implementation this
 * provider replaces, and the outward response never differs either way.
 */
class authEmailRecoveryProvider implements authRecoveryProvider
{
    public function getId(): string
    {
        return 'email';
    }

    public function claims(string $identifier): bool
    {
        return (bool)filter_var(trim($identifier), FILTER_VALIDATE_EMAIL);
    }

    public function start(string $identifier): authRecoveryHandle
    {
        $email           = self::normalize($identifier);
        $identifier_hash = hash('sha256', $email);
        $contact_id      = self::findContactId($email);

        $token = '';
        if ($contact_id !== null) {
            $model  = new authPasswordRecoveryModel();
            $issued = $model->issue($contact_id, $this->getId(), $identifier_hash, false, self::linkTtl());
            $token  = $issued['token'];

            $this->sendEmail($email, $token);
        }

        return new authRecoveryHandle($this->getId(), $token);
    }

    public function needsCode(): bool
    {
        return false;
    }

    public function verifyCode(authRecoveryHandle $handle, string $code): bool
    {
        // needsCode() is always false here — the core never reaches a code
        // step for this provider. A defensive stub, not a real branch.
        throw new BadMethodCallException('authEmailRecoveryProvider has no code step.');
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

    /**
     * Mailbox names are case-sensitive in the standard and never in practice;
     * the domain never is. Same rule authProfileSectionLoginEmail::normalize()
     * applies — without it 'Bob@x.com' and 'bob@x.com' would be two different
     * lookups (and, before this provider existed, silently were).
     */
    private static function normalize(string $identifier): string
    {
        return mb_strtolower(trim($identifier));
    }

    private static function findContactId(string $email): ?int
    {
        $model = new waContactModel();
        $sql = "SELECT c.id FROM wa_contact c
                JOIN wa_contact_emails e ON c.id = e.contact_id
                WHERE e.email = s:email
                  AND e.sort = 0
                  AND c.password != ''
                  AND c.is_user > -1
                ORDER BY c.id LIMIT 1";

        $row = $model->query($sql, ['email' => $email])->fetchAssoc();
        return $row ? (int)$row['id'] : null;
    }

    private static function linkTtl(): int
    {
        return (int)authConfig::get('recovery_link_ttl', 3600);
    }

    private function sendEmail(string $email, string $token): void
    {
        $url = wa()->getRouteUrl('auth/frontend/recovery', ['token' => $token], true);

        try {
            $message = new waMailMessage(_w('Password recovery'));
            $message->setBody(
                '<p>'.htmlspecialchars(_w('Open the link below to set a new password.')).'</p>'.
                '<p><a href="'.htmlspecialchars($url).'">'.htmlspecialchars($url).'</a></p>'
            );
            $message->setTo($email);

            if (!$message->send()) {
                throw new waException('waMailMessage::send() returned false');
            }
        } catch (Exception $e) {
            waLog::log('auth recovery email failed: ' . $e->getMessage(), 'auth.log');
        }
    }
}
