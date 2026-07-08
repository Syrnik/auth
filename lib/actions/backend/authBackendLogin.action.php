<?php

class authBackendLoginAction extends authBackendDomainSettingsAction
{
    protected function getUrlSegment(): string
    {
        return 'login';
    }

    protected function getTemplateName(): string
    {
        return 'BackendLogin';
    }

    protected function getViewData(array $config, string $domain): array
    {
        return [
            'available_methods' => $this->getAvailableMethods($config, $domain),
        ];
    }

    protected function collectSectionData(string $domain, array $current): array
    {
        $post = waRequest::post();

        return [
            'login_methods'   => (array)($post['login_methods'] ?? []),
            'adapters'        => $this->collectAdapterCredentials((array)($post['adapters'] ?? [])),
            'plugin_settings' => $this->collectPluginSettings(
                $this->getAuthPluginInstances(),
                (array)($post['plugin_settings'] ?? [])
            ),
        ];
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
     * All installed auth-method plugins: [plugin_id => authPlugin&authMethod instance]
     */
    private function getAuthPluginInstances(): array
    {
        return $this->getPluginInstancesOf(authMethod::class);
    }
}
