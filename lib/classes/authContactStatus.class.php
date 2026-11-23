<?php

/**
 * The one place that writes 'confirmed' into wa_contact_emails.status /
 * wa_contact_data.status — the framework's own storage for "this address has
 * been proven", already used by waSignupAction, waBaseLoginAction and
 * waBaseForgotPasswordAction (see docs/adr/005-value-confirmation.md). This
 * app only ever needed to start writing to it.
 *
 * Two things every call site would otherwise have to know on its own:
 *
 *   - the two models disagree on what "unknown" looks like
 *     (waContactEmailsModel::STATUS_UNKNOWN is the string 'unknown',
 *     waContactDataModel::STATUS_UNKNOWN is NULL) — nothing here reads that
 *     constant, only STATUS_CONFIRMED, so the asymmetry never leaks out;
 *   - updateContactPhoneStatus() only runs cleanPhoneNumber() on the value it
 *     is given, not the domain's transformPhone() step preparePhones() also
 *     applies — a value not normalized the same way as the one on record
 *     simply won't be found.
 *
 * Both writers return false only when the value is no longer on that
 * contact (already verified against their source: a status already equal is
 * a silent no-op, not a false) — a real "the value moved from under us"
 * case, logged here rather than swallowed.
 *
 * A successful stamp of the login value also settles a pending profile
 * confirmation of that very value (auth_profile_confirm, AUTH-986): proven
 * by OTP login, recovery or a signup link, it would otherwise keep showing
 * "awaiting confirmation" until the row expired. See dropSettledPending().
 */
class authContactStatus
{
    public static function confirmEmail(int $contact_id, string $email): bool
    {
        $email = trim($email);
        $ok = (new waContactEmailsModel())->updateContactEmailStatus(
            $contact_id,
            $email,
            waContactEmailsModel::STATUS_CONFIRMED
        );

        if (!$ok) {
            waLog::log(
                sprintf('auth: cannot confirm email for contact %d — value not on contact', $contact_id),
                'auth.log'
            );
        } else {
            self::dropSettledPending('email', $contact_id, $email);
        }

        return $ok;
    }

    public static function confirmPhone(int $contact_id, string $phone): bool
    {
        $phone = authProfileValues::normalizePhone($phone);
        $ok = (new waContactDataModel())->updateContactPhoneStatus(
            $contact_id,
            $phone,
            waContactDataModel::STATUS_CONFIRMED
        );

        if (!$ok) {
            waLog::log(
                sprintf('auth: cannot confirm phone for contact %d — value not on contact', $contact_id),
                'auth.log'
            );
        } else {
            self::dropSettledPending('phone', $contact_id, $phone);
        }

        return $ok;
    }

    /**
     * Stamps whichever value currently sits at sort = 0 for $field — the
     * login value, per authProfileSectionLogin's own docblock. Used by call
     * sites (OTP login, recovery completion) that know which contact and
     * which field just proved itself, but not the value's exact shape.
     */
    public static function confirmPrimary(string $field, int $contact_id): bool
    {
        if ($field === 'email') {
            $row = (new waContactEmailsModel())->getByField(['contact_id' => $contact_id, 'sort' => 0]);
            return $row ? self::confirmEmail($contact_id, (string)$row['email']) : false;
        }

        if ($field === 'phone') {
            $row = (new waContactDataModel())
                ->getByField(['contact_id' => $contact_id, 'field' => 'phone', 'sort' => 0]);
            return $row ? self::confirmPhone($contact_id, (string)$row['value']) : false;
        }

        return false;
    }

    public static function isPrimaryConfirmed(string $field, int $contact_id): bool
    {
        if ($field === 'email') {
            $row = (new waContactEmailsModel())->getByField(['contact_id' => $contact_id, 'sort' => 0]);
            return $row && (string)$row['status'] === waContactEmailsModel::STATUS_CONFIRMED;
        }

        if ($field === 'phone') {
            $row = (new waContactDataModel())
                ->getByField(['contact_id' => $contact_id, 'field' => 'phone', 'sort' => 0]);
            return $row && (string)$row['status'] === waContactDataModel::STATUS_CONFIRMED;
        }

        return false;
    }

    /**
     * Deletes the field's pending profile confirmation once $value — just
     * stamped confirmed — has made it moot.
     *
     * Only when both hold:
     *   - the pending row is for $value itself;
     *   - $value is the contact's login value (sort = 0).
     *
     * The second condition is what tells "confirm the value I already have"
     * from "make this my login": a pending login change is always to a value
     * other than the current login one, even when that value already sits on
     * the contact as a secondary — and confirming that secondary must not
     * cancel the change. One row per (contact, field) at most
     * (authProfileConfirmModel::issue()), so fetching it is enough.
     *
     * Both sides normalized here, not compared in SQL: rows are stored the
     * way the profile normalizes (lowercased email, normalizePhone()), while
     * callers hand in the value as they have it.
     */
    private static function dropSettledPending(string $field, int $contact_id, string $value): void
    {
        $value = self::normalizeFor($field, $value);
        if ($value === '') {
            return;
        }

        if ($field === 'email') {
            $primary = (new waContactEmailsModel())->getByField(['contact_id' => $contact_id, 'sort' => 0]);
            $primary_value = $primary ? (string)$primary['email'] : '';
        } else {
            $primary = (new waContactDataModel())
                ->getByField(['contact_id' => $contact_id, 'field' => 'phone', 'sort' => 0]);
            $primary_value = $primary ? (string)$primary['value'] : '';
        }
        if (self::normalizeFor($field, $primary_value) !== $value) {
            return;
        }

        $model = new authProfileConfirmModel();
        $row = $model->getByField(['contact_id' => $contact_id, 'field' => $field]);
        if ($row && self::normalizeFor($field, (string)$row['value']) === $value) {
            $model->deleteById($row['id']);
        }
    }

    private static function normalizeFor(string $field, string $value): string
    {
        return $field === 'email'
            ? mb_strtolower(trim($value))
            : authProfileValues::normalizePhone($value);
    }
}
