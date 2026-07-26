<?php

/**
 * Creates auth_profile_confirm — login changes waiting to be proven, see
 * authProfileConfirmModel.
 *
 * lib/config/db.php is only applied whole on an app's first launch
 * (waAppConfig::install()); an installation that already ran gets new tables
 * from files like this one, keyed by the timestamp in the filename
 * (waAppConfig::getUpdateFiles()). So the schema below has to stay in step with
 * db.php by hand — createSchema() is given the same declaration rather than a
 * hand-written CREATE TABLE, so the two cannot drift in spelling.
 */

$schema = include(wa()->getAppPath('lib/config/db.php', 'auth'));

if (isset($schema['auth_profile_confirm'])) {
    (new waModel())->createSchema(['auth_profile_confirm' => $schema['auth_profile_confirm']]);
}
