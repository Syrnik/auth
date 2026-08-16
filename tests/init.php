<?php
/**
 * PHPUnit bootstrap for the auth app.
 *
 * Style and reasoning follow the sdekint plugin's tests/init.php — the
 * pattern this suite was adapted from (AUTH-87).
 */

error_reporting(E_ALL | E_NOTICE);

// HTTP_HOST must exist before waSystem::getInstance(): without it
// wa()->getRouting()->getDomain() returns null, and authConfig::currentDomain()
// (declared : string) throws a TypeError — every test that touches domain
// settings would fail before reaching its own code. Overridable via
// AUTH_TEST_HOST for checkouts where 'syrnik.local' isn't a routed domain.
$_SERVER['HTTP_HOST']   = getenv('AUTH_TEST_HOST') ?: 'syrnik.local';
$_SERVER['REQUEST_URI'] = '/';

// dirname(__FILE__, 4): the app lives at wa-apps/auth/tests/, two levels
// shallower than a plugin's wa-apps/<app>/plugins/<plugin>/tests/.
require_once dirname(__FILE__, 4) . '/wa-config/SystemConfig.class.php';
waSystem::getInstance(null, new SystemConfig());

// Registers autoloading for the app's own classes (and its plugins').
wa('auth');

// tests/ is outside the app's autoload scan (it only scans lib/), so shared
// test helpers must be required explicitly.
require_once dirname(__FILE__) . '/authTestTemporaryTablesTrait.php';
require_once dirname(__FILE__) . '/authTestConfigOverrideTrait.php';
