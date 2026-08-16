<?php
/**
 * Psalm autoloader (psalm82.xml / psalm85.xml) — boots just enough of the framework
 * for class/type resolution. Deliberately not tests/init.php: psalm only needs
 * waSystem's autoloading registered, not a running app (wa('auth')) or the test
 * helper traits.
 */
require_once dirname(__FILE__, 4) . '/wa-config/SystemConfig.class.php';
waSystem::getInstance(null, new SystemConfig());
