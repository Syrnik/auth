<?php

class authConfig
{
    private static array $cache    = [];
    private static ?array $defaults = null;
    private static ?array $saved   = null;

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

    /**
     * Global config (distribution defaults merged with saved global keys).
     * Does not apply per-domain overrides. Used for the "По умолчанию" settings screen.
     */
    public static function getGlobal(): array
    {
        $merged = array_merge(self::loadDefaults(), self::loadSaved());
        unset($merged['domains']);
        return $merged;
    }

    /**
     * Three-level merge: distribution defaults → global saved → per-domain override.
     */
    public static function getMerged(string $domain = null): array
    {
        $domain = $domain ?: self::currentDomain();

        if (!isset(self::$cache[$domain])) {
            $global = self::loadSaved();
            $merged = array_merge(self::loadDefaults(), $global);
            unset($merged['domains']);

            if (isset($global['domains'][$domain]) && is_array($global['domains'][$domain])) {
                $merged = array_merge($merged, $global['domains'][$domain]);
            }

            self::$cache[$domain] = $merged;
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
