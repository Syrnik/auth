<?php
return [
    // <backend_url>/auth/<path> => 'module/action' (пустой action = дефолтный action модуля)
    'settings/?' => 'backend/settings',
    'plugins/?'  => 'plugins/',
    'design/?'   => 'design/',
    ''           => 'backend/',
];
