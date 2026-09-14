<?php

class authBackendLoginAction extends authBackendDomainSettingsAction
{
    /**
     * Instances confirmed for deletion this submit, together with the
     * already-resolved plugin object to purge their data with. Filled by
     * collectSectionData() (before the config write, where resolution can
     * still veto a deletion), consumed by afterSave() (after it, where the
     * purge is safe to run against the now-current config).
     *
     * @var array<int, array{plugin: authPlugin, dir: string, key: string}>
     */
    private array $pendingPurges = [];

    /**
     * Human-readable notices about this save, shown once by the template —
     * currently only "this connection is still configured on another domain,
     * so its account links were kept" (AUTH-440, see
     * authPlugin::getLinkSource()'s note that a link carries no domain
     * dimension while the connection config does).
     *
     * @var string[]
     */
    private array $notices = [];

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
            'notices'           => $this->notices,
        ];
    }

    protected function collectSectionData(string $domain, array $current): array
    {
        $post    = waRequest::post();
        $plugins = $this->getAuthPluginInstances();

        $deleted = $this->resolveDeletedInstances(
            $plugins,
            $current,
            $domain,
            (array)($post['deleted_instances'] ?? [])
        );

        return [
            'login_methods'   => $this->stripDeletedLoginMethods((array)($post['login_methods'] ?? []), $deleted),
            'adapters'        => $this->collectAdapterCredentials((array)($post['adapters'] ?? [])),
            'plugin_settings' => $this->collectPluginSettings(
                $plugins,
                (array)($post['plugin_settings'] ?? []),
                (array)($current['plugin_settings'] ?? []),
                $deleted
            ),
        ];
    }

    protected function afterSave(string $domain): void
    {
        foreach ($this->pendingPurges as $purge) {
            $count = $purge['plugin']->purgeInstanceData();
            waLog::log(sprintf(
                'auth: connection %s:%s deleted on %s, %d account link(s) removed',
                $purge['dir'],
                $purge['key'],
                $domain,
                $count
            ), 'auth/auth.log');
        }
        $this->pendingPurges = [];
    }

    /**
     * Validates the admin's explicit deletion list (deleted_instances[dir][]
     * in POST, see BackendLogin.html) against instance keys that actually
     * exist for this domain, resolves each surviving one to a plugin object,
     * and queues it in $this->pendingPurges for afterSave(). Returns the
     * accepted list in the shape collectPluginSettings() and
     * stripDeletedLoginMethods() both expect: [plugin_dir => [instance_key, ...]].
     *
     * A requested key that isn't a real instance of this plugin (forged POST,
     * or a stale key from a page loaded before another admin's save) is
     * dropped silently — same as a plugin id collectPluginSettings() has
     * never heard of. A key that resolves but is still configured on another
     * domain is accepted for THIS domain's config (the admin asked to remove
     * the connection here) but not queued for a purge — see
     * authPlugin::getLinkSource()'s note that links carry no domain
     * dimension, so purging here would sign out users of the other domain's
     * live connection with the same instance key. A key whose plugin object
     * won't resolve (authPluginManager::get() returned null — plugin
     * directory or class gone) is dropped from the accepted list entirely:
     * refusing that one deletion outright is safer than writing a config
     * change whose matching purge can never run.
     *
     * @param array $plugins [plugin_id => authPlugin instance], as returned by getAuthPluginInstances()
     * @param array $current this domain's currently stored config (login_methods, plugin_settings)
     * @param string[][] $posted_deleted raw deleted_instances[dir][] from POST
     * @return array<string, string[]> [plugin_dir => [instance_key, ...]]
     */
    private function resolveDeletedInstances(array $plugins, array $current, string $domain, array $posted_deleted): array
    {
        $accepted = [];

        foreach ($plugins as $dir => $plugin) {
            if (empty($plugin->getInfo()['multi_instance'])) {
                continue;
            }
            $requested = array_map('strval', (array)($posted_deleted[$dir] ?? []));
            if (!$requested) {
                continue;
            }

            $known = $this->knownInstanceKeys($dir, $current);

            foreach ($requested as $key) {
                $key = strtolower(trim($key));
                if (!authPluginManager::isValidInstanceKey($key) || !isset($known[$key])) {
                    continue;
                }

                $instance_plugin = $this->resolveInstancePlugin($dir, $key);
                if ($instance_plugin === null) {
                    continue;
                }

                $accepted[$dir][] = $key;

                $other_domain = $this->instanceConfiguredElsewhere($dir, $key, $domain);
                if ($other_domain !== null) {
                    $this->notices[] = sprintf(
                        _w('Connection "%s" was removed for this site; its linked accounts were kept because the same connection is still configured on %s.'),
                        $key,
                        $other_domain
                    );
                    continue;
                }

                $this->pendingPurges[] = ['plugin' => $instance_plugin, 'dir' => $dir, 'key' => $key];
            }
        }

        return $accepted;
    }

    /**
     * Every instance key currently known for one plugin on this domain:
     * saved settings blocks plus any key already enabled in login_methods
     * with no saved settings yet — the same union getPluginInstances()
     * builds below, so a key visible on screen always validates for
     * deletion and a key never seen on this domain never does.
     *
     * @return array<string, true>
     */
    private function knownInstanceKeys(string $dir, array $current): array
    {
        $method_id = $dir . '_plugin';
        $keys      = array_fill_keys(array_keys((array)($current['plugin_settings'][$dir] ?? [])), true);

        foreach ((array)($current['login_methods'] ?? []) as $id) {
            [$base_id, $instance] = authPluginManager::splitInstance($id);
            if ($base_id === $method_id && $instance !== null) {
                $keys[$instance] = true;
            }
        }

        return $keys;
    }

    /**
     * Whether $dir's instance $key is configured on a domain other than
     * $domain — checked against every domain the auth app is routed on, not
     * just the ones with a connection currently enabled, since a disabled-
     * but-configured instance still owns whatever links it already made.
     * Returns that other domain's name, or null if this domain is the only
     * one.
     */
    private function instanceConfiguredElsewhere(string $dir, string $key, string $domain): ?string
    {
        $method_id = $dir . '_plugin:' . $key;

        foreach ($this->getDomains() as $other) {
            if ($other === $domain) {
                continue;
            }
            $other_config = authConfig::getMerged($other);
            if (isset($other_config['plugin_settings'][$dir][$key]) && is_array($other_config['plugin_settings'][$dir][$key])) {
                return $other;
            }
            if (in_array($method_id, (array)($other_config['login_methods'] ?? []), true)) {
                return $other;
            }
        }

        return null;
    }

    /**
     * Drops every '<dir>_plugin:<key>' entry a confirmed deletion covers from
     * a posted login_methods list — the connection is gone, so it cannot
     * stay checked regardless of what the (possibly stale) checkbox in POST
     * said.
     *
     * @param array $deleted [plugin_dir => [instance_key, ...]]
     */
    private function stripDeletedLoginMethods(array $login_methods, array $deleted): array
    {
        $removed_ids = [];
        foreach ($deleted as $dir => $keys) {
            foreach ($keys as $key) {
                $removed_ids[$dir . '_plugin:' . $key] = true;
            }
        }
        if (!$removed_ids) {
            return $login_methods;
        }

        return array_values(array_filter(
            $login_methods,
            static fn($id) => !isset($removed_ids[$id])
        ));
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
     * Returns [instance_key => ['enabled' => bool, 'controls' => [...], 'links' => int]]
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
                'links'    => $this->instanceLinkCount($dir, $key),
            ];
        }

        foreach ($enabled as $id) {
            [$id, $instance] = authPluginManager::splitInstance($id);
            if ($id === $method_id && $instance !== null && !isset($instances[$instance])) {
                $instances[$instance] = [
                    'links'    => $this->instanceLinkCount($dir, $instance),
                    'enabled'  => true,
                    'controls' => $plugin->getSettingsControls([]),
                ];
            }
        }

        return $instances;
    }

    /**
     * How many accounts are linked through one instance — asked of the
     * instance's own plugin object (authPlugin::countInstanceContacts()),
     * not computed here from getLinkSource() directly: a plugin that keeps
     * its own storage overrides that method together with
     * purgeInstanceData(), and the count shown before a deletion has to
     * match what the deletion actually purges (AUTH-440). One query per
     * instance rather than one for the whole screen — the screen shows at
     * most a handful of named connections, and going through the plugin
     * object is what keeps the count and the purge from disagreeing for a
     * custom-storage plugin.
     */
    private function instanceLinkCount(string $dir, string $key): int
    {
        $instance_plugin = $this->resolveInstancePlugin($dir, $key);
        return $instance_plugin !== null ? $instance_plugin->countInstanceContacts() : 0;
    }

    /**
     * Resolves a named instance to its plugin object — the same
     * authPluginManager::get() call both the count shown
     * (instanceLinkCount()) and the purge queued on deletion
     * (resolveDeletedInstances()) go through, so they can never disagree
     * about what this instance is.
     */
    private function resolveInstancePlugin(string $dir, string $key): ?authPlugin
    {
        $instance_plugin = authPluginManager::get($dir . '_plugin:' . $key);
        return $instance_plugin instanceof authPlugin ? $instance_plugin : null;
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
