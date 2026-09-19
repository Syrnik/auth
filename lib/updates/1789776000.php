<?php

/**
 * Two unrelated ALTERs, shipped together because both are for AUTH-51
 * (docs/adr/005-value-confirmation.md):
 *
 * - auth_profile_confirm gains `section`, the id of the section that issued
 *   the row. Without it, authFrontendMyConfirmAction::apply() has to resolve
 *   a token's section by `field` alone at redemption time — and if
 *   login_methods changes while a token is live, a row issued by the plain
 *   'email' section can be redeemed by 'login_email' instead, promoting a
 *   secondary address to the login without ever checking isTaken() on it.
 *   Existing rows get '' and fall back to the old field-only resolution —
 *   safe, since they were all issued before a plain section could issue one
 *   at all.
 *
 * - auth_signup_confirm gains `email` (the address a confirmation link was
 *   actually mailed to, read back instead of whatever sits at sort = 0 when
 *   the link is clicked) and a non-unique `contact` key
 *   (authSignupConfirmModel::deleteByContact(), used by the new resend flow
 *   so a resend cannot leave two live tokens for one contact).
 *
 * Probed defensively per the root AGENTS.md convention (a SELECT in
 * try/catch, not "does the column exist" introspection) — a plain re-run
 * must not fail on a column that is already there. No TRUNCATE: unlike
 * 1789689600.php's table, a stale row here just falls back to old behavior
 * (empty `section`) or an empty `email` (authContactStatus::confirmEmail()
 * then no-ops on a row already redeemed the old way, since the token is
 * deleted on redemption regardless).
 */

$model = new waModel();

try {
    $model->exec('SELECT `section` FROM `auth_profile_confirm` LIMIT 0');
} catch (waDbException $e) {
    $model->exec(
        "ALTER TABLE `auth_profile_confirm`
            ADD COLUMN `section` VARCHAR(64) NOT NULL DEFAULT '' AFTER `attempts`"
    );
}

try {
    $model->exec('SELECT `email` FROM `auth_signup_confirm` LIMIT 0');
} catch (waDbException $e) {
    $model->exec(
        "ALTER TABLE `auth_signup_confirm`
            ADD COLUMN `email` VARCHAR(255) NOT NULL DEFAULT '' AFTER `contact_id`,
            ADD KEY `contact` (`contact_id`)"
    );
}
