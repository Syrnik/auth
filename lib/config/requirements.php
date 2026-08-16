<?php

/**
 * Floor is 8.2, not the framework's own 7.4+: authPluginManager already relies on
 * str_ends_with() (8.0+), and the installer/CI tooling (psalm82.xml/psalm85.xml,
 * .github/workflows/php-version-check.yml) targets 8.2-8.5 — see README's
 * "Требования" section and AGENTS.md.
 */
return [
    'php' => ['version' => '>=8.2.0', 'strict' => true],
];
