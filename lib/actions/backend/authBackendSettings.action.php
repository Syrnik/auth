<?php

class authBackendSettingsAction extends waViewAction
{
    public function execute(): void
    {
        $this->setLayout(new authDefaultLayout());
        $this->setTemplate('BackendSettings');

        $domain = waRequest::get('domain', '', 'string');

        if (waRequest::method() === 'post') {
            $this->save($domain);
            return;
        }

        $config = $domain ? authConfig::getMerged($domain) : authConfig::getGlobal();

        $this->view->assign([
            'domain'             => $domain,
            'available_methods'  => $this->getAvailableMethods($config),
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
            'adapters'         => $this->collectAdapterCredentials((array)($post['adapters'] ?? [])),
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

    /**
     * Every method the admin can enable for this domain: built-in form methods,
     * every framework-level OAuth adapter (Webasyst ID, VK, Google, ...) whether
     * or not it's configured yet, and this app's own plugins.
     * Returns [id => ['name' => ..., 'oauth' => bool, 'controls' => [field_id => ['label'|'html', 'value']]]]
     */
    private function getAvailableMethods(array $config): array
    {
        $methods = [
            'email' => ['name' => 'Email / пароль'],
            'login' => ['name' => 'Логин / пароль'],
            'phone' => ['name' => 'Телефон (OTP)'],
        ];

        $all_adapters = (array)($config['adapters'] ?? []);

        foreach (authPluginManager::getSystemAdapters() as $id => $provider_id) {
            // 'waid' falls back to the framework's own Webasyst ID registration
            // so already-working installs show their real, effective credentials.
            $credentials = $id === 'waid'
                ? authWaidMethod::getCredentials()
                : (array)($all_adapters[$id] ?? []);
            $adapter = wa()->getAuth($provider_id, $credentials);

            $controls = [];
            foreach ($adapter->getControls($credentials) as $field_id => $control) {
                $controls[$field_id] = is_array($control)
                    ? $control
                    : ['label' => $control, 'value' => $credentials[$field_id] ?? ''];
            }

            $methods[$id] = [
                'name'     => $adapter->getName(),
                'oauth'    => true,
                'controls' => $controls,
            ];
        }

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
                        $methods[$dir] = ['name' => $info['name'] ?? $dir];
                    }
                }
            }
        }

        return $methods;
    }

    /**
     * Pulls posted credential fields for every known adapter (ignores anything else in POST).
     */
    private function collectAdapterCredentials(array $post_adapters): array
    {
        $result = [];
        foreach (authPluginManager::getSystemAdapters() as $id => $provider_id) {
            if (!empty($post_adapters[$id]) && is_array($post_adapters[$id])) {
                $result[$id] = array_map('strval', $post_adapters[$id]);
            }
        }
        return $result;
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
