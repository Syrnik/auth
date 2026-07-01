<?php

class authBackendPluginsAction extends waViewAction
{
    public function execute(): void
    {
        $plugins_path = wa()->getAppPath('plugins', 'auth');
        $plugins = [];

        if (is_dir($plugins_path)) {
            foreach (scandir($plugins_path) as $dir) {
                if ($dir[0] === '.' || !is_dir($plugins_path . '/' . $dir)) {
                    continue;
                }
                $info_path = $plugins_path . '/' . $dir . '/lib/config/plugin.php';
                if (!file_exists($info_path)) {
                    continue;
                }
                $info = (array)include($info_path);
                $plugins[$dir] = [
                    'id'           => $dir,
                    'name'         => $info['name'] ?? $dir,
                    'version'      => $info['version'] ?? '—',
                    'is_auth'      => !empty($info['is_auth']),
                    'is_guard'     => !empty($info['is_guard']),
                    'is_captcha'   => !empty($info['is_captcha']),
                    'is_challenge' => !empty($info['is_challenge']),
                ];
            }
        }

        $this->view->assign('plugins', $plugins);
    }
}
