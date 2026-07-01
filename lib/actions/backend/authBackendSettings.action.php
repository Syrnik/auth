<?php

class authBackendSettingsAction extends waViewAction
{
    public function execute(): void
    {
        $domain = waRequest::get('domain', '', 'string');

        if (waRequest::method() === 'post') {
            $this->save($domain);
            return;
        }

        $config = $domain ? authConfig::getMerged($domain) : authConfig::getGlobal();

        $this->view->assign([
            'domain'             => $domain,
            'available_methods'  => $this->getAvailableMethods(),
            'available_captchas' => $this->getAvailableCaptchas(),
            'config'             => $config,
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

        $config_path = wa()->getConfig()->getConfigPath('config.php', true, 'auth');
        $existing = file_exists($config_path) ? (array)include($config_path) : [];

        if ($domain) {
            $existing['domains'][$domain] = $new;
        } else {
            $domains = $existing['domains'] ?? [];
            $existing = $new;
            if ($domains) {
                $existing['domains'] = $domains;
            }
        }

        waUtils::varExportToFile($existing, $config_path);
        authConfig::clearCache();

        $redirect = '?module=backend&action=settings&saved=1';
        if ($domain) {
            $redirect .= '&domain=' . urlencode($domain);
        }
        wa()->getResponse()->redirect($redirect);
    }

    private function getAvailableMethods(): array
    {
        $methods = [
            'email' => 'Email / пароль',
            'waid'  => 'Webasyst ID',
            'phone' => 'Телефон (OTP)',
        ];

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
                        $methods[$dir] = $info['name'] ?? $dir;
                    }
                }
            }
        }

        return $methods;
    }

    private function getAvailableCaptchas(): array
    {
        $captchas = ['' => '(нет)'];

        $plugins_path = wa()->getAppPath('plugins', 'auth');
        if (is_dir($plugins_path)) {
            foreach (scandir($plugins_path) as $dir) {
                if ($dir[0] === '.' || !is_dir($plugins_path . '/' . $dir)) {
                    continue;
                }
                $info_path = $plugins_path . '/' . $dir . '/lib/config/plugin.php';
                if (file_exists($info_path)) {
                    $info = (array)include($info_path);
                    if (!empty($info['is_captcha'])) {
                        $captchas[$dir] = $info['name'] ?? $dir;
                    }
                }
            }
        }

        return $captchas;
    }
}
