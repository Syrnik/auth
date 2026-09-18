<?php

/**
 * Adds channel/identifier_hash/code_hash/attempts to auth_password_recovery
 * and replaces its original single-purpose (email-link-only) shape with the
 * shared one every authRecoveryProvider uses — see authPasswordRecoveryModel
 * and docs/adr/004-recovery-channels.md (AUTH-48).
 *
 * Unlike 1785110400.php/1789430400.php (new tables, applied via createSchema()
 * against the same declaration as lib/config/db.php), this table already
 * exists on any installation that ran the app before AUTH-48, so this is an
 * ALTER, probed defensively per the root AGENTS.md convention (a SELECT in
 * try/catch, not "does the column exist" introspection) — a plain re-run
 * (e.g. a second deploy of the same version) must not fail on a column that
 * is already there.
 *
 * Existing rows are truncated rather than backfilled: a pending recovery
 * lives at most an hour (see authPasswordRecoveryModel::issue()'s default
 * TTL), so nothing here is worth keeping, and a row stuck with the schema
 * default ('email' / '' / '') is a state the new code should never have to
 * reason about.
 */

$model = new waModel();

try {
    $model->exec('SELECT `channel`, `identifier_hash`, `code_hash`, `attempts` FROM `auth_password_recovery` LIMIT 0');
} catch (waDbException $e) {
    $model->exec("TRUNCATE TABLE `auth_password_recovery`");
    $model->exec(
        "ALTER TABLE `auth_password_recovery`
            ADD COLUMN `channel` VARCHAR(64) NOT NULL DEFAULT 'email' AFTER `contact_id`,
            ADD COLUMN `identifier_hash` VARCHAR(64) NOT NULL DEFAULT '' AFTER `channel`,
            ADD COLUMN `code_hash` VARCHAR(255) NOT NULL DEFAULT '' AFTER `token`,
            ADD COLUMN `attempts` INT(11) NOT NULL DEFAULT 0 AFTER `code_hash`,
            ADD UNIQUE KEY `identifier` (`identifier_hash`, `channel`)"
    );
}
