<?php
return [
    // <backend_url>/auth/<path> => 'module/action' (пустой action = дефолтный action модуля)
    'settings/<domain:[^/]+>/?'            => 'backend/dashboard',
    'settings/<domain:[^/]+>/login/?'      => 'backend/login',
    'settings/<domain:[^/]+>/signup/?'     => 'backend/signup',
    'settings/<domain:[^/]+>/recovery/?'   => 'backend/recovery',
    'settings/<domain:[^/]+>/captcha/?'    => 'backend/captcha',
    'settings/<domain:[^/]+>/guards/?'     => 'backend/guards',
    'settings/<domain:[^/]+>/challenges/?' => 'backend/challenges',
    'plugins/?'                      => 'plugins/',
    'design/?'                       => 'design/',
    // Not a plain '': waAppConfig::getRoutingRules() merges this file with
    // routing.php whenever env is backend, and routing.php has its own ''
    // key (frontend app root) — an identical string key would let that
    // array_merge() silently overwrite this rule. '/?' matches the same
    // empty remainder without colliding with it.
    '/?'                              => 'backend/',
];
