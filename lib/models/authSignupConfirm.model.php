<?php

/**
 * Email-confirmation tokens issued at registration. Mirrors
 * authPasswordRecoveryModel: each new token first sweeps expired rows, so the
 * table stays bounded without a cron. The 24h lifetime is derived from
 * created_datetime (no separate expiry column), matching the original schema.
 */
class authSignupConfirmModel extends waModel
{
    protected $table = 'auth_signup_confirm';

    private const TTL_HOURS = 24;

    /**
     * Issue a fresh confirmation token for a contact and drop expired ones.
     */
    public function createToken(int $contact_id): string
    {
        $this->deleteExpired();

        $token = bin2hex(random_bytes(32));
        $this->insert([
            'contact_id'       => $contact_id,
            'token'            => $token,
            'created_datetime' => date('Y-m-d H:i:s'),
        ]);

        return $token;
    }

    /**
     * Return the row for a still-valid token, or null. An expired token is
     * deleted on the spot so a stale link can't be reused.
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

        if (strtotime($row['created_datetime']) < time() - self::TTL_HOURS * 3600) {
            $this->deleteById($row['id']);
            return null;
        }

        return $row;
    }

    public function deleteExpired(): void
    {
        $this->exec(
            "DELETE FROM " . $this->table . " WHERE created_datetime < ?",
            date('Y-m-d H:i:s', time() - self::TTL_HOURS * 3600)
        );
    }
}
