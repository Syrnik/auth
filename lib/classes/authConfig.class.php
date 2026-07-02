<?php

class authConfig
{
    private static array $cache    = [];
    private static ?array $defaults = null;
    private static ?array $saved   = null;

    /**
     * $default only applies when $key is absent from config. Several keys
     * (e.g. redirect_after_login) are deliberately declared as null in
     * lib/config/config.php, so their stored value IS null — callers must
     * chain `?: fallback` on the result instead of passing $default here.
     */
    public static function get(string $key, $default = null, string $domain = null)
    {
        $config = self::getMerged($domain);
        return array_key_exists($key, $config) ? $config[$key] : $default;
    }

    public static function getLoginMethods(string $domain = null): array
    {
        return (array)self::get('login_methods', [], $domain);
    }

    /**
     * The auth app is active on a site only once the admin enables at least one
     * login method for that domain. A domain with no enabled methods has no
     * login/registration at all — it's just a landing page. There is no global
     * "default" fallback: every site carries its own settings or has none.
     */
    public static function isEnabled(string $domain = null): bool
    {
        return count(self::getLoginMethods($domain)) > 0;
    }

    public static function getChallengeMethods(string $domain = null): array
    {
        return (array)self::get('challenge_methods', [], $domain);
    }

    public static function getGuardPlugins(string $domain = null): array
    {
        return (array)self::get('guard_plugins', [], $domain);
    }

    /**
     * Per-domain settings of an app plugin (e.g. a guard's blacklist rules),
     * stored under 'plugin_settings' => [plugin_id => [...]] in the domain config.
     */
    public static function getPluginSettings(string $plugin_id, string $domain = null): array
    {
        $all = (array)self::get('plugin_settings', [], $domain);
        return (array)($all[$plugin_id] ?? []);
    }

    /**
     * Saved credentials for a system/OAuth adapter, keyed by method id (e.g. 'waid', 'vkontakte').
     */
    public static function getAdapterCredentials(string $id, string $domain = null): array
    {
        $adapters = (array)self::get('adapters', [], $domain);
        return (array)($adapters[$id] ?? []);
    }

    // -------------------------------------------------------------------------

    /**
     * Two-level merge: distribution defaults (per-field fallback) → per-domain
     * saved settings. There is no global settings layer — a domain either has
     * its own saved config or falls back to the (disabled) distribution defaults.
     */
    public static function getMerged(string $domain = null): array
    {
        $domain = $domain ?: self::currentDomain();

        if (!isset(self::$cache[$domain])) {
            $saved      = self::loadSaved();
            $per_domain = (isset($saved['domains'][$domain]) && is_array($saved['domains'][$domain]))
                ? $saved['domains'][$domain]
                : [];

            self::$cache[$domain] = array_merge(self::loadDefaults(), $per_domain);
        }

        return self::$cache[$domain];
    }

    private static function loadDefaults(): array
    {
        if (self::$defaults === null) {
            // false = distribution file: wa-apps/auth/lib/config/config.php (read-only)
            $path = wa()->getConfig()->getConfigPath('config.php', false, 'auth');
            self::$defaults = file_exists($path) ? (array)include($path) : [];
        }
        return self::$defaults;
    }

    private static function loadSaved(): array
    {
        if (self::$saved === null) {
            // true = user override: wa-config/apps/auth/config.php (writable)
            $path = wa()->getConfig()->getConfigPath('config.php', true, 'auth');
            self::$saved = file_exists($path) ? (array)include($path) : [];
        }
        return self::$saved;
    }

    public static function currentDomain(): string
    {
        return wa()->getRouting()->getDomain(null, true);
    }

    public static function clearCache(): void
    {
        self::$cache    = [];
        self::$defaults = null;
        self::$saved    = null;
    }
}
