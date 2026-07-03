<?php

/**
 * One-time password-recovery tokens. Replaces the old scheme of stashing tokens
 * in wa_app_settings, which had no expiry dimension and left every unused token
 * there forever. Each row carries its own expiry, and expired rows are swept on
 * every new request (createToken), so the table is self-cleaning without a cron.
 */
class authPasswordRecoveryModel extends waModel
{
    protected $table = 'auth_password_recovery';

    /**
     * Issue a fresh recovery token for a contact and drop any expired ones.
     * @param int $ttl Lifetime in seconds.
     */
    public function createToken(int $contact_id, int $ttl = 3600): string
    {
        $this->deleteExpired();

        $token = bin2hex(random_bytes(27));
        $now   = time();
        $this->insert([
            'contact_id'       => $contact_id,
            'token'            => $token,
            'created_datetime' => date('Y-m-d H:i:s', $now),
            'expire_datetime'  => date('Y-m-d H:i:s', $now + $ttl),
        ]);

        return $token;
    }

    /**
     * Return the row for a still-valid token, or null. An expired token is
     * deleted on the spot so a second lookup can't reuse it.
     */
    public function getValid(string $token): ?array
    {
        if ($token === '') {
            return null;
        }

        $row = $this->getByField('token', $token);
        if (!$row) {
            return null;
        }

        if (strtotime($row['expire_datetime']) < time()) {
            $this->deleteById($row['id']);
            return null;
        }

        return $row;
    }

    public function deleteByToken(string $token): void
    {
        if ($token !== '') {
            $this->deleteByField('token', $token);
        }
    }

    public function deleteExpired(): void
    {
        $this->exec(
            "DELETE FROM " . $this->table . " WHERE expire_datetime < ?",
            date('Y-m-d H:i:s')
        );
    }
}
