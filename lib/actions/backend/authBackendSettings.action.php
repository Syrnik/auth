<?php

class authBackendSettingsAction extends waViewAction
{
    public function execute(): void
    {
        // Determine which domain we're editing
        $domain = waRequest::get('domain', '', 'string') ?: authConfig::currentDomain();

        if (waRequest::method() === 'post') {
            $this->save($domain);
            return;
        }

        $available_methods = [
            'email' => 'Email / пароль',
            'waid'  => 'Webasyst ID',
            'phone' => 'Телефон (OTP)',
        ];

        // Add installed auth plugins
        $plugins_path = wa()->getAppPath('plugins', 'auth');
        if (is_dir($plugins_path)) {
            foreach (scandir($plugins_path) as $dir) {
                if ($dir[0] === '.' || !is_dir($plugins_path . '/' . $dir)) {
                    continue;
                }
                $info_path = $plugins_path . '/' . $dir . '/lib/config/plugin.php';
                if (file_exists($info_path)) {
                    $info = (array)include($info_path);
                    if (!empty($info['is_auth'])) {
                        $available_methods[$dir . '_plugin'] = $info['name'] ?? $dir;
                    }
                }
            }
        }

        $available_captchas = ['(нет)' => ''];
        if (is_dir($plugins_path)) {
            foreach (scandir($plugins_path) as $dir) {
                if ($dir[0] === '.' || !is_dir($plugins_path . '/' . $dir)) {
                    continue;
                }
                $info_path = $plugins_path . '/' . $dir . '/lib/config/plugin.php';
                if (file_exists($info_path)) {
                    $info = (array)include($info_path);
                    if (!empty($info['is_captcha'])) {
                        $available_captchas[$info['name'] ?? $dir] = $dir . '_plugin';
                    }
                }
            }
        }

        $config = authConfig::getMerged($domain);

        $this->view->assign([
            'domain'            => $domain,
            'available_methods' => $available_methods,
            'available_captchas' => $available_captchas,
            'config'            => $config,
        ]);
    }

    private function save(string $domain): void
    {
        $post = waRequest::post();

        $new = [
            'login_methods'    => (array)($post['login_methods'] ?? ['email']),
            'signup_enabled'   => !empty($post['signup_enabled']),
            'signup_confirm'   => !empty($post['signup_confirm']),
            'recovery_enabled' => !empty($post['recovery_enabled']),
            'rememberme'       => !empty($post['rememberme']),
            'captcha_plugin'   => (string)($post['captcha_plugin'] ?? ''),
        ];

        // Load existing saved config
        // true = user override: wa-config/apps/auth/config.php; getConfigPath() also creates the directory
        $config_path = wa()->getConfig()->getConfigPath('config.php', true, 'auth');
        $existing = file_exists($config_path) ? (array)include($config_path) : [];

        // Merge domain-specific settings
        if (!isset($existing['domains'])) {
            $existing['domains'] = [];
        }
        $existing['domains'][$domain] = $new;
        waUtils::varExportToFile($existing, $config_path);

        authConfig::clearCache();

        wa()->getResponse()->redirect('?module=backend&action=settings&domain=' . urlencode($domain) . '&saved=1');
    }
}
