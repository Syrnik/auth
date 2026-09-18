<?php

/**
 * One pending password-recovery request per (identifier, channel) — not one
 * per contact, and not one per token. See docs/adr/004-recovery-channels.md,
 * decision 6: the unique key is on the hashed identifier so that decoy rows
 * (decision 5 — every one shares contact_id = 0) never collide with each
 * other or with a real contact's own row.
 *
 * A row with a non-empty code_hash belongs to a code-based provider
 * (authRecoveryProvider::needsCode() === true, e.g. authPhoneRecoveryProvider)
 * and is found again from the session-held authRecoveryHandle, never from
 * the URL. A row with an empty code_hash belongs to a link-based provider
 * (authEmailRecoveryProvider): the mailed token is itself the proof, so
 * complete() is reached straight from recovery/<token>/.
 *
 * Replaces the old scheme of stashing tokens in wa_app_settings, which had no
 * expiry dimension and left every unused token there forever. Each row
 * carries its own expiry, and expired rows are swept on every new request
 * (issue()), so the table is self-cleaning without a cron.
 */
class authPasswordRecoveryModel extends waModel
{
    protected $table = 'auth_password_recovery';

    /**
     * Guesses allowed at a phone code before the request is thrown away —
     * same cap as authProfileConfirmModel::MAX_ATTEMPTS, and for the same
     * reason: a six-digit code is brute-forceable within its TTL otherwise.
     * A decoy row (see class docblock) is capped identically, so it fails the
     * same way a real one would.
     */
    public const MAX_ATTEMPTS = 5;

    /**
     * Issue a fresh recovery token (and code, for a code-based provider) and
     * drop any expired rows plus any live row already pending for this exact
     * (identifier, channel) — a second request replaces the first instead of
     * leaving two live tokens for the same identifier, same reasoning as
     * authProfileConfirmModel::issue().
     *
     * @param int $contact_id Real contact id, or 0 for a decoy (the
     *                        identifier did not resolve) — decision 5 of the
     *                        ADR requires the decoy row to exist and behave
     *                        identically to a real one, all the way through
     *                        verifyCode()/complete().
     * @param string $channel The issuing provider's id (a recovery_channels entry).
     * @param string $identifier_hash sha256 of the provider's normalized identifier.
     * @param bool $with_code Whether this provider proves the request by a
     *                        typed-back code (true) or by the token alone (false).
     * @param int $ttl Lifetime in seconds.
     * @return array{token: string, code: ?string} $code is null for $with_code === false.
     */
    public function issue(int $contact_id, string $channel, string $identifier_hash, bool $with_code, int $ttl = 3600): array
    {
        $this->deleteExpired();
        $this->deleteByField(['identifier_hash' => $identifier_hash, 'channel' => $channel]);

        $token = bin2hex(random_bytes(27));
        $code  = $with_code ? (string)random_int(100000, 999999) : null;
        $now   = time();

        $this->insert([
            'contact_id'       => $contact_id,
            'channel'          => $channel,
            'identifier_hash'  => $identifier_hash,
            'token'            => $token,
            // A decoy gets a real hash of a real (never-sent) code, not an
            // empty string — see needsCode()'s docblock.
            'code_hash'        => $code === null ? '' : password_hash($code, PASSWORD_DEFAULT),
            'attempts'         => 0,
            'created_datetime' => date('Y-m-d H:i:s', $now),
            'expire_datetime'  => date('Y-m-d H:i:s', $now + $ttl),
        ]);

        return ['token' => $token, 'code' => $code];
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

    /**
     * Whether a row is proven by a typed-back code rather than the token
     * alone — read off the same non-empty-code_hash rule
     * authProfileConfirmModel::needsCode() uses. A decoy row's code_hash is
     * never empty (see issue()), so it reads as needing a code exactly like a
     * real one — the two must never diverge at this check.
     */
    public function needsCode(array $row): bool
    {
        return (string)$row['code_hash'] !== '';
    }

    /**
     * Check a submitted code against a pending row, real or decoy alike. A
     * wrong guess costs an attempt, and running out throws the request away
     * entirely — same as authProfileConfirmModel::verifyCode(), and for the
     * same reason.
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

    public function deleteByToken(string $token): void
    {
        if ($token !== '') {
            $this->deleteByField('token', $token);
        }
    }

    /**
     * Every other pending recovery a real contact might have (a different
     * channel, or an older one the visitor abandoned) — called once a
     * recovery completes, so an unrelated still-live link/code for the same
     * account cannot be replayed afterwards. Never touches decoy rows
     * (contact_id = 0 matches no real contact).
     */
    public function deleteByContact(int $contact_id): void
    {
        if ($contact_id > 0) {
            $this->deleteByField('contact_id', $contact_id);
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
