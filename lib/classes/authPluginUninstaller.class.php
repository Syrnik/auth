<?php

/**
 * Removes every trace an auth plugin leaves in this app's own storage when the
 * plugin itself is deleted (AUTH-559, docs/adr/006-plugin-uninstall-cleanup.md):
 * its references in every domain's config and the per-contact data of every
 * instance it ever had.
 *
 * Called from authPlugin::uninstall() — the only hook the framework offers:
 * the installer ("Plugins" screen → installer/?module=plugins&action=remove)
 * fires no event on deletion, it just calls waPlugin::uninstall() and then
 * deletes the plugin directory. The directory is still in place while this
 * runs, so instance objects still resolve through authPluginManager::get().
 *
 * Unlike deleting a single named instance (ADR 002, decision 3), there is no
 * "still configured on another domain" exception: the plugin is gone for every
 * domain at once, so every domain's config is cleaned and every instance's
 * data purged unconditionally — including domains that are no longer routed,
 * whose stale entries would otherwise come back with a reinstall just the same.
 */
class authPluginUninstaller
{
    /**
     * Lists resolved strictly through authPluginManager::get()-style ids:
     * a plugin is only ever '<id>_plugin' or '<id>_plugin:<key>' here, and a
     * bare id is a built-in ('email', 'phone', ...), never this plugin.
     */
    private const STRICT_LISTS = ['login_methods', 'signup_methods', 'challenge_methods', 'recovery_channels'];

    /**
     * Keys whose reader strips the '_plugin' suffix only if present
     * (authPluginManager::getGuardsEnabled(), getCaptchaPlugin(),
     * getThrottleStore()), so a bare '<id>' refers to the plugin too — the
     * Guards screen stores guard ids exactly like that.
     */
    private const LENIENT_LISTS   = ['guard_plugins'];
    private const LENIENT_SCALARS = ['captcha_plugin', 'throttle_store'];

    public static function run(authPlugin $plugin): void
    {
        // Directory id, not getId(): authMethod/authChallenge/... redefine
        // getId() as the method's own id, which a plugin may choose freely.
        $plugin_id   = (string)$plugin->getInfo()['id'];
        $config_path = wa()->getConfig()->getConfigPath('config.php', true, 'auth');
        $saved       = file_exists($config_path) ? (array)include($config_path) : [];
        $domains     = (isset($saved['domains']) && is_array($saved['domains'])) ? $saved['domains'] : [];

        $multi_instance = !empty($plugin->getInfo()['multi_instance']);
        $instance_keys  = $multi_instance ? self::collectInstanceKeys($domains, $plugin_id) : [];

        $cleaned = self::stripPluginFromDomains($domains, $plugin_id);
        if ($cleaned !== $domains) {
            $saved['domains'] = $cleaned;
            waUtils::varExportToFile($saved, $config_path);
            authConfig::clearCache();
        }

        // Same order as the Login screen (ADR 002): config first, then data.
        $targets = [];
        if ($multi_instance) {
            foreach ($instance_keys as $key) {
                $instance = authPluginManager::get($plugin_id . '_plugin:' . $key);
                if ($instance instanceof authPlugin) {
                    $targets[$key] = $instance;
                }
            }
        } else {
            $targets[''] = $plugin;
        }

        foreach ($targets as $key => $target) {
            $count = $target->purgeInstanceData();
            waLog::log(sprintf(
                'auth: plugin %s%s uninstalled, %d contact(s) data removed',
                $plugin_id,
                $key !== '' ? ':' . $key : '',
                $count
            ), 'auth/auth.log');
        }
    }

    /**
     * Every instance key a multi_instance plugin has anywhere in the saved
     * config: settings blocks under plugin_settings[<id>] plus instance-
     * qualified ids in any list, across all domains — the same union the
     * Login screen treats as "known instances", widened to every domain.
     *
     * A link whose instance no config mentions any more (deleted before
     * AUTH-440 existed) cannot be found this way; its source can't be
     * derived from anything that still exists.
     *
     * @return string[]
     */
    public static function collectInstanceKeys(array $domains, string $plugin_id): array
    {
        $keys = [];
        foreach ($domains as $config) {
            if (!is_array($config)) {
                continue;
            }
            $settings = $config['plugin_settings'][$plugin_id] ?? null;
            if (is_array($settings)) {
                foreach (array_keys($settings) as $key) {
                    $keys[(string)$key] = true;
                }
            }
            foreach (self::referencedIds($config) as $id) {
                [$base, $instance] = authPluginManager::splitInstance($id);
                if ($instance !== null && self::baseMatches($base, $plugin_id, true)) {
                    $keys[$instance] = true;
                }
            }
        }

        return array_values(array_filter(
            array_map('strval', array_keys($keys)),
            [authPluginManager::class, 'isValidInstanceKey']
        ));
    }

    /**
     * Pure transform over the saved 'domains' array: drops plugin_settings[<id>]
     * and every reference to the plugin (any of its instances) from each
     * domain's lists; a scalar reference becomes null, same as "not set".
     */
    public static function stripPluginFromDomains(array $domains, string $plugin_id): array
    {
        foreach ($domains as $domain => $config) {
            if (!is_array($config)) {
                continue;
            }

            if (isset($config['plugin_settings']) && is_array($config['plugin_settings'])
                && array_key_exists($plugin_id, $config['plugin_settings'])
            ) {
                unset($config['plugin_settings'][$plugin_id]);
            }

            foreach (self::STRICT_LISTS as $list) {
                if (isset($config[$list]) && is_array($config[$list])) {
                    $config[$list] = self::filterList($config[$list], $plugin_id, false);
                }
            }
            foreach (self::LENIENT_LISTS as $list) {
                if (isset($config[$list]) && is_array($config[$list])) {
                    $config[$list] = self::filterList($config[$list], $plugin_id, true);
                }
            }
            foreach (self::LENIENT_SCALARS as $key) {
                if (isset($config[$key]) && is_string($config[$key]) && self::refersTo($config[$key], $plugin_id, true)) {
                    $config[$key] = null;
                }
            }

            $domains[$domain] = $config;
        }

        return $domains;
    }

    private static function filterList(array $list, string $plugin_id, bool $lenient): array
    {
        $filtered = array_filter(
            $list,
            static fn($id) => !(is_string($id) && self::refersTo($id, $plugin_id, $lenient))
        );
        return count($filtered) === count($list) ? $list : array_values($filtered);
    }

    private static function refersTo(string $id, string $plugin_id, bool $lenient): bool
    {
        [$base] = authPluginManager::splitInstance($id);
        return self::baseMatches($base, $plugin_id, $lenient);
    }

    private static function baseMatches(string $base, string $plugin_id, bool $lenient): bool
    {
        return $base === $plugin_id . '_plugin' || ($lenient && $base === $plugin_id);
    }

    /**
     * Every plugin-ish id one domain's config mentions, for instance-key
     * discovery. Lenient forms are included; baseMatches() decides per call.
     *
     * @return string[]
     */
    private static function referencedIds(array $config): array
    {
        $ids = [];
        foreach (array_merge(self::STRICT_LISTS, self::LENIENT_LISTS) as $list) {
            foreach ((array)($config[$list] ?? []) as $id) {
                if (is_string($id)) {
                    $ids[] = $id;
                }
            }
        }
        foreach (self::LENIENT_SCALARS as $key) {
            if (isset($config[$key]) && is_string($config[$key])) {
                $ids[] = $config[$key];
            }
        }
        return $ids;
    }
}
