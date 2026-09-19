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
     *
     * @param string $email the address the link is about to be mailed to —
     *                       stored so authFrontendConfirmAction stamps
     *                       exactly that address confirmed, not whatever sits
     *                       at sort = 0 when the link is eventually clicked
     *                       (the visitor may have changed it in between). See
     *                       docs/adr/005-value-confirmation.md.
     */
    public function createToken(int $contact_id, string $email = ''): string
    {
        $this->deleteExpired();
        // A resend replaces the earlier token rather than piling up alongside
        // it — two live links for one contact would let a stale one (mailed
        // to an address since corrected) go on working after a fresh resend,
        // the same reasoning authProfileConfirmModel::issue() already
        // documents for its own replace-on-reissue.
        $this->deleteByContact($contact_id);

        $token = bin2hex(random_bytes(32));
        $this->insert([
            'contact_id'       => $contact_id,
            'email'            => $email,
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

    public function deleteByContact(int $contact_id): void
    {
        $this->deleteByField('contact_id', $contact_id);
    }
}
