<?php

class authConfig
{
    private static array $cache = [];

    /**
     * Get a config value for the given domain (or current domain if null).
     */
    public static function get(string $key, $default = null, string $domain = null)
    {
        $config = self::getMerged($domain);
        return array_key_exists($key, $config) ? $config[$key] : $default;
    }

    public static function getLoginMethods(string $domain = null): array
    {
        return (array)self::get('login_methods', ['email'], $domain);
    }

    public static function getChallengeMethods(string $domain = null): array
    {
        return (array)self::get('challenge_methods', [], $domain);
    }

    public static function getGuardPlugins(string $domain = null): array
    {
        return (array)self::get('guard_plugins', [], $domain);
    }

    // -------------------------------------------------------------------------

    public static function getMerged(string $domain = null): array
    {
        $domain = $domain ?: self::currentDomain();

        if (!isset(self::$cache[$domain])) {
            $defaults = self::loadDefaults();
            $global   = self::loadSaved();

            // Global-level keys (everything except 'domains')
            $merged = array_merge($defaults, $global);
            unset($merged['domains']);

            // Domain-level override
            if (isset($global['domains'][$domain]) && is_array($global['domains'][$domain])) {
                $merged = array_merge($merged, $global['domains'][$domain]);
            }

            self::$cache[$domain] = $merged;
        }

        return self::$cache[$domain];
    }

    private static function loadDefaults(): array
    {
        static $defaults = null;
        if ($defaults === null) {
            // false = distribution file: wa-apps/auth/lib/config/config.php (read-only)
            $path = wa()->getConfig()->getConfigPath('config.php', false, 'auth');
            $defaults = file_exists($path) ? (array)include($path) : [];
        }
        return $defaults;
    }

    private static function loadSaved(): array
    {
        static $saved = null;
        if ($saved === null) {
            // true = user override: wa-config/apps/auth/config.php (writable)
            $path = wa()->getConfig()->getConfigPath('config.php', true, 'auth');
            $saved = file_exists($path) ? (array)include($path) : [];
        }
        return $saved;
    }

    public static function currentDomain(): string
    {
        return wa()->getRouting()->getDomain(null, true);
    }

    public static function clearCache(): void
    {
        self::$cache = [];
    }
}
