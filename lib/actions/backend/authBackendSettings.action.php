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

        // Sites the auth app is mounted on. Each carries its own settings;
        // there is no global "default" screen anymore.
        $domains = array_keys(wa()->getRouting()->getByApp('auth'));

        if (!$domains) {
            $this->view->assign([
                'domain'              => '',
                'no_domains'          => true,
                'available_methods'   => [],
                'available_captchas'  => [],
                'available_guards'    => [],
                'available_challenges' => [],
                'config'              => [],
            ]);
            return;
        }

        // Default to the first site when none (or an unknown one) is requested.
        if (!$domain || !in_array($domain, $domains, true)) {
            $domain = $domains[0];
        }

        $config = authConfig::getMerged($domain);

        $this->view->assign([
            'domain'               => $domain,
            'no_domains'           => false,
            'available_methods'    => $this->getAvailableMethods($config, $domain),
            'available_captchas'   => $this->getAvailableCaptchas($domain),
            'available_guards'     => $this->getAvailableGuards($domain),
            'available_challenges' => $this->getAvailableChallenges($domain),
            'config'               => $config,
        ]);
    }

    private function save(string $domain): void
    {
        if (!$domain) {
            wa()->getResponse()->redirect('?module=backend&action=settings');
            return;
        }

        $post = waRequest::post();

        // Guard, captcha, challenge, and auth plugins may all carry per-domain
        // settings; a plugin that is more than one of these appears once
        // (keys are plugin dir names).
        $guards     = $this->getGuardPluginInstances();
        $captchas   = $this->getCaptchaPluginInstances();
        $challenges = $this->getChallengePluginInstances();
        $plugins    = $guards + $captchas + $challenges + $this->getAuthPluginInstances();

        $new = [
            'login_methods'     => (array)($post['login_methods'] ?? []),
            'signup_enabled'    => !empty($post['signup_enabled']),
            'signup_confirm'    => !empty($post['signup_confirm']),
            'recovery_enabled'  => !empty($post['recovery_enabled']),
            'rememberme'        => !empty($post['rememberme']),
            'captcha_plugin'    => (string)($post['captcha_plugin'] ?? ''),
            'adapters'          => $this->collectAdapterCredentials((array)($post['adapters'] ?? [])),
            'guard_plugins'     => array_values(array_intersect(
                (array)($post['guard_plugins'] ?? []),
                array_keys($guards)
            )),
            'challenge_methods' => array_values(array_intersect(
                (array)($post['challenge_methods'] ?? []),
                // Unlike guard_plugins/captcha_plugin (lenient, suffix optional),
                // challenge_methods is resolved by authPluginManager::get(), the
                // same strict resolver login_methods uses — a plugin id there
                // MUST carry the '_plugin' suffix or it's mistaken for a builtin
                // method and silently resolves to null.
                array_map(fn($dir) => $dir . '_plugin', array_keys($challenges))
            )),
            'plugin_settings'   => $this->collectPluginSettings($plugins, (array)($post['plugin_settings'] ?? [])),
        ];

        $config_path = wa()->getConfig()->getConfigPath('config.php', true, 'auth');
        $existing = file_exists($config_path) ? (array)include($config_path) : [];

        // Store settings strictly per domain; any legacy top-level keys from the
        // old global-defaults layout are intentionally dropped here.
        $domains = (isset($existing['domains']) && is_array($existing['domains'])) ? $existing['domains'] : [];
        $domains[$domain] = $new;

        waUtils::varExportToFile(['domains' => $domains], $config_path);
        authConfig::clearCache();

        wa()->getResponse()->redirect(
            '?module=backend&action=settings&saved=1&domain=' . urlencode($domain)
        );
    }

    /**
     * Every method the admin can enable for this domain: built-in form methods,
     * every framework-level OAuth adapter (Webasyst ID, VK, Google, ...) whether
     * or not it's configured yet, and this app's own plugins.
     * Returns [id => ['name' => ..., 'oauth' => bool, 'controls' => [field_id => ['label'|'html', 'value']]]]
     * Plugin entries are keyed '{dir}_plugin' and add 'plugin_id'; a plugin with
     * multi_instance => true gets 'instances' (existing named instances with their
     * controls) and 'new_controls' (empty controls for the add-instance template)
     * instead of a single 'controls' block.
     */
    private function getAvailableMethods(array $config, string $domain): array
    {
        $methods = [];

        foreach (authPluginManager::getBuiltinFormMethods() as $id => $class) {
            $methods[$id] = ['name' => (new $class())->getName()];
        }

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

        foreach ($this->getAuthPluginInstances() as $dir => $plugin) {
            $info      = $plugin->getInfo();
            $method_id = $dir . '_plugin';
            $entry     = [
                'name'      => $info['name'] ?? $dir,
                'plugin_id' => $dir,
            ];
            if (!empty($info['auth_type']) && $info['auth_type'] === 'oauth') {
                $entry['oauth'] = true;
            }
            if (!empty($info['multi_instance'])) {
                $entry['multi_instance'] = true;
                $entry['instances']      = $this->getPluginInstances($dir, $plugin, $domain, $config);
                $entry['new_controls']   = $plugin->getSettingsControls([]);
            } else {
                $entry['plugin_controls'] = $plugin->getSettingsControls(
                    authConfig::getPluginSettings($dir, $domain)
                );
            }
            $methods[$method_id] = $entry;
        }

        return $methods;
    }

    /**
     * Existing named instances of a multi-instance plugin on this domain:
     * every settings block under plugin_settings[plugin_id], plus instances
     * enabled in login_methods that have no saved settings yet (so they still
     * show up instead of silently disappearing from the screen).
     * Returns [instance_key => ['enabled' => bool, 'controls' => [...]]]
     */
    private function getPluginInstances(string $dir, authPlugin $plugin, string $domain, array $config): array
    {
        // $dir, not $plugin->getId(): authMethod plugins override getId()
        // to return their method id ('testmulti_plugin'), not the dir name.
        $method_id = $dir . '_plugin';
        $enabled   = (array)($config['login_methods'] ?? []);

        $instances = [];
        foreach (authConfig::getPluginSettings($dir, $domain) as $key => $settings) {
            if (!is_array($settings)) {
                continue;
            }
            $instances[$key] = [
                'enabled'  => in_array($method_id . ':' . $key, $enabled, true),
                'controls' => $plugin->getSettingsControls($settings),
            ];
        }

        foreach ($enabled as $id) {
            [$id, $instance] = authPluginManager::splitInstance($id);
            if ($id === $method_id && $instance !== null && !isset($instances[$instance])) {
                $instances[$instance] = [
                    'enabled'  => true,
                    'controls' => $plugin->getSettingsControls([]),
                ];
            }
        }

        return $instances;
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

    /**
     * Guard plugins the admin can enable for this domain, with their per-domain
     * settings controls: [id => ['name' => ..., 'controls' => [field_id => [...]]]]
     */
    private function getAvailableGuards(string $domain): array
    {
        $guards = [];
        foreach ($this->getGuardPluginInstances() as $id => $plugin) {
            $guards[$id] = [
                'name'     => $plugin->getName(),
                'controls' => $plugin->getSettingsControls(authConfig::getPluginSettings($id, $domain)),
            ];
        }
        return $guards;
    }

    /**
     * All installed guard plugins: [plugin_id => authPlugin&authGuard instance]
     */
    private function getGuardPluginInstances(): array
    {
        return $this->getPluginInstancesOf(authGuard::class);
    }

    /**
     * Challenge (2FA) plugins the admin can enable for this domain, with their
     * per-domain settings controls. Entries are keyed '{dir}_plugin' — same
     * suffix convention as getAvailableMethods() — because challenge_methods,
     * like login_methods, is resolved by authPluginManager::get(), which
     * requires the suffix to tell a plugin id apart from a builtin method id.
     * Returns [method_id => ['name' => ..., 'plugin_id' => dir, 'controls' => [field_id => [...]]]]
     */
    private function getAvailableChallenges(string $domain): array
    {
        $challenges = [];
        foreach ($this->getChallengePluginInstances() as $dir => $plugin) {
            $challenges[$dir . '_plugin'] = [
                'name'      => $plugin->getName(),
                'plugin_id' => $dir,
                'controls'  => $plugin->getSettingsControls(authConfig::getPluginSettings($dir, $domain)),
            ];
        }
        return $challenges;
    }

    /**
     * All installed challenge plugins: [plugin_id => authPlugin&authChallenge instance]
     */
    private function getChallengePluginInstances(): array
    {
        return $this->getPluginInstancesOf(authChallenge::class);
    }

    /**
     * All installed auth-method plugins: [plugin_id => authPlugin&authMethod instance]
     */
    private function getAuthPluginInstances(): array
    {
        return $this->getPluginInstancesOf(authMethod::class);
    }

    private function getPluginInstancesOf(string $interface): array
    {
        $result = [];
        $plugins_path = wa()->getAppPath('plugins', 'auth');
        if (!is_dir($plugins_path)) {
            return $result;
        }
        foreach (scandir($plugins_path) as $dir) {
            if ($dir[0] === '.' || !is_dir($plugins_path . '/' . $dir)) {
                continue;
            }
            $plugin = authPluginManager::get($dir . '_plugin');
            if ($plugin instanceof $interface && $plugin instanceof authPlugin) {
                $result[$dir] = $plugin;
            }
        }
        return $result;
    }

    /**
     * Runs each plugin's own POST data through its prepareSettings().
     * Settings are kept even for currently disabled plugins, so toggling
     * a guard off and on does not lose its rules.
     *
     * For multi_instance plugins POST carries one block per named instance
     * (plugin_settings[plugin][instance_key][field]); each block goes through
     * prepareSettings() separately. An instance absent from POST is deleted —
     * the screen always renders every existing instance, so absence means
     * the admin removed it.
     */
    private function collectPluginSettings(array $plugins, array $post_settings): array
    {
        $result = [];
        foreach ($plugins as $id => $plugin) {
            if (!isset($post_settings[$id]) || !is_array($post_settings[$id])) {
                continue;
            }
            if (empty($plugin->getInfo()['multi_instance'])) {
                $result[$id] = $plugin->prepareSettings($post_settings[$id]);
                continue;
            }
            $instances = [];
            foreach ($post_settings[$id] as $key => $values) {
                $key = strtolower(trim((string)$key));
                if (!is_array($values) || !preg_match('~^[a-z0-9][a-z0-9_-]*$~', $key)) {
                    continue;
                }
                $instances[$key] = $plugin->prepareSettings($values);
            }
            if ($instances) {
                $result[$id] = $instances;
            }
        }
        return $result;
    }

    /**
     * Installed captcha plugins with their per-domain settings controls (site
     * key/secret, etc.): [plugin_id => ['name' => ..., 'controls' => [...]]]
     */
    private function getAvailableCaptchas(string $domain): array
    {
        $captchas = [];
        foreach ($this->getCaptchaPluginInstances() as $dir => $plugin) {
            $captchas[$dir] = [
                'name'     => $plugin->getInfo()['name'] ?? $dir,
                'controls' => $plugin->getSettingsControls(authConfig::getPluginSettings($dir, $domain)),
            ];
        }
        return $captchas;
    }

    /**
     * All installed captcha plugins: [plugin_id => authPlugin&authCaptcha instance]
     */
    private function getCaptchaPluginInstances(): array
    {
        return $this->getPluginInstancesOf(authCaptcha::class);
    }
}
