<?php
return [
    // <backend_url>/auth/<path> => 'module/action' (пустой action = дефолтный action модуля)
    'settings/?' => 'backend/settings',
    'plugins/?'  => 'backend/plugins',
    ''           => 'backend/',
];
