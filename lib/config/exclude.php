<?php
return [
    // AUTH-87: tests/ and its runner config never ship — see tests/init.php and phpunit.xml
    // in the app root. webasystCompress.cli.php turns each entry into a regex matched
    // against relative paths, so 'tests' alone (no '/*') already excludes everything
    // under it — no need for sdekint's belt-and-suspenders 'tests'/'tests/*'/'*/tests/*'.
    'tests',
    'phpunit.xml',
    '.phpunit.result.cache',
    // Static analysis / PHP-version-compatibility tooling (see AGENTS.md) — dev-only,
    // its own autoloader (tests/psalm-init.php) is already covered by 'tests' above.
    'psalm82.xml',
    'psalm85.xml',
    'psalm82-baseline.xml',
    'psalm85-baseline.xml',
    // .github/ is not listed here: webasystCompress.cli.php's own blacklist already
    // drops every leading-dot directory ('directory with leading dot').
    // Contributor-facing docs, not part of the running app.
    'AGENTS.md',
    'docs',
];
