<?php

/**
 * Creates auth_throttle — brute-force throttle counters, see authThrottleModel
 * and docs/adr/003-credential-throttle.md (AUTH-49).
 *
 * lib/config/db.php is only applied whole on an app's first launch
 * (waAppConfig::install()); an installation that already ran gets new tables
 * from files like this one, keyed by the timestamp in the filename
 * (waAppConfig::getUpdateFiles()). So the schema below has to stay in step with
 * db.php by hand — createSchema() is given the same declaration rather than a
 * hand-written CREATE TABLE, so the two cannot drift in spelling.
 */

$schema = include(wa()->getAppPath('lib/config/db.php', 'auth'));

if (isset($schema['auth_throttle'])) {
    (new waModel())->createSchema(['auth_throttle' => $schema['auth_throttle']]);
}
