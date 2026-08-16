<?php
return [
    // AUTH-87: tests/ and its runner config never ship — see tests/init.php and phpunit.xml
    // in the app root. webasystCompress.cli.php turns each entry into a regex matched
    // against relative paths, so 'tests' alone (no '/*') already excludes everything
    // under it — no need for sdekint's belt-and-suspenders 'tests'/'tests/*'/'*/tests/*'.
    'tests',
    'phpunit.xml',
    '.phpunit.result.cache',
    // Contributor-facing docs, not part of the running app.
    'AGENTS.md',
    'docs',
];
