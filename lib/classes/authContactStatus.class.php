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
}
