<?php

/**
 * Login changes waiting to be proven: the new email or phone a visitor asked
 * for, held here until they answer on that value itself.
 *
 * Decision 2 of docs/adr/001-profile-config-boundaries.md: an email or phone
 * that is an active login method has a different life cycle from a contact
 * field — the new value is proven before it replaces the old one, or a typo
 * (or someone else's address) locks the account out. So the contact is not
 * touched at all until the proof arrives; until then the change lives here.
 *
 * Structured like authSignupConfirmModel and authPasswordRecoveryModel: every
 * issued token first sweeps the expired ones, so the table stays bounded with
 * no cron. What is new here is that a row also carries the value to apply, and
 * — for phones — the hash of a code the visitor types back rather than a link
 * they follow.
 */
class authProfileConfirmModel extends waModel
{
    protected $table = 'auth_profile_confirm';

    /**
     * How long a pending change stays valid. Shorter than the signup
     * confirmation's 24 hours: this one changes an existing account's way in,
     * and the visitor is doing it right now with the mail client open.
     */
    private const TTL_HOURS = 1;

    /** Guesses allowed at a phone code before the request is thrown away. */
    private const MAX_ATTEMPTS = 5;

    /**
     * Record a pending change and return its row, including the plain code when
     * one was generated — this is the only moment it exists in the clear, and
     * the caller has to send it before it is gone.
     *
     * An earlier request for the same field is dropped rather than kept
     * alongside: two live tokens for one login mean the older mail still works
     * after the visitor corrected a typo, which is the opposite of what asking
     * again means.
     *
     * @param bool $with_code whether the value is proven by a code (phone) or
     *                        by following a link (email)
     * @return array ['token' => string, 'code' => string|null]
     */
    public function issue(int $contact_id, string $field, string $value, bool $with_code): array
    {
        $this->deleteExpired();
        $this->deleteByField(['contact_id' => $contact_id, 'field' => $field]);

        $token = bin2hex(random_bytes(32));
        $code  = $with_code ? (string)random_int(100000, 999999) : null;

        $this->insert([
            'contact_id'       => $contact_id,
            'field'            => $field,
            'value'            => $value,
            'token'            => $token,
            'code_hash'        => $code === null ? '' : password_hash($code, PASSWORD_DEFAULT),
            'attempts'         => 0,
            'created_datetime' => date('Y-m-d H:i:s'),
        ]);

        return ['token' => $token, 'code' => $code];
    }

    /**
     * The row for a still-valid token, or null. An expired token is deleted on
     * the spot so a stale link cannot be retried.
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

    /**
     * Whether a row is confirmed by a code the visitor types rather than by a
     * link they follow.
     */
    public function needsCode(array $row): bool
    {
        return (string)$row['code_hash'] !== '';
    }

    /**
     * Check a submitted code against a pending row.
     *
     * A wrong guess costs an attempt, and running out throws the request away
     * entirely — a six-digit code within an hour is brute-forceable otherwise.
     * The caller tells the two failures apart by re-reading the row: it is gone
     * when the guesses ran out.
     */
    public function verifyCode(array $row, string $code): bool
    {
        if (!password_verify($code, (string)$row['code_hash'])) {
            $attempts = (int)$row['attempts'] + 1;
            if ($attempts >= self::MAX_ATTEMPTS) {
                $this->deleteById($row['id']);
            } else {
                $this->updateById($row['id'], ['attempts' => $attempts]);
            }
            return false;
        }

        return true;
    }

    /**
     * Guesses left on a pending row, for telling the visitor before they run out.
     */
    public function attemptsLeft(array $row): int
    {
        return max(0, self::MAX_ATTEMPTS - (int)$row['attempts']);
    }

    /**
     * The change this contact is currently waiting to confirm for a field, or
     * null. Read by the profile section, which has to say "we sent a code to
     * +7…, confirm it" instead of offering the same edit form again.
     */
    public function getPending(int $contact_id, string $field): ?array
    {
        $row = $this->getByField(['contact_id' => $contact_id, 'field' => $field]);
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
            "DELETE FROM ".$this->table." WHERE created_datetime < ?",
            date('Y-m-d H:i:s', time() - self::TTL_HOURS * 3600)
        );
    }
}
