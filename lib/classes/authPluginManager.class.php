<?php

class authPluginManager
{
    private static array $cache = [];

    /**
     * Get a method/plugin instance by its config ID.
     * 'email' → built-in authEmailMethod
     * 'github_plugin' → plugin from plugins/github/
     */
    public static function get(string $id): ?object
    {
        if (str_ends_with($id, '_plugin')) {
            return self::loadPlugin(substr($id, 0, -7));
        }
        return self::loadBuiltin($id);
    }

    /**
     * All active primary auth methods for the current domain (from login_methods).
     * Returns ['method_id' => authMethod instance]
     */
    public static function getEnabled(): array
    {
        $result = [];
        foreach (authConfig::getLoginMethods() as $id) {
            $method = self::get($id);
            if ($method instanceof authMethod) {
                $result[$id] = $method;
            }
        }
        return $result;
    }

    /**
     * Active challenge methods for current domain.
     * Returns [authChallenge instance, ...]
     */
    public static function getChallengeEnabled(): array
    {
        $result = [];
        foreach (authConfig::getChallengeMethods() as $id) {
            $method = self::get($id);
            if ($method instanceof authChallenge) {
                $result[] = $method;
            }
        }
        return $result;
    }

    /**
     * Guard plugins for current domain, filtered by the call point.
     * $point = 'login' → only guards with guard_login:true
     * $point = 'signup' → only guards with guard_signup:true
     * Returns [authGuard instance, ...]
     */
    public static function getGuardsEnabled(string $point): array
    {
        $result = [];
        foreach (authConfig::getGuardPlugins() as $id) {
            $plugin_id = str_ends_with($id, '_plugin') ? substr($id, 0, -7) : $id;
            $info = self::readPluginInfo($plugin_id);
            if (!$info) {
                continue;
            }
            $flag = 'guard_' . $point;
            if (empty($info[$flag])) {
                continue;
            }
            $plugin = self::loadPlugin($plugin_id, 'is_guard');
            if ($plugin instanceof authGuard) {
                $result[] = $plugin;
            }
        }
        return $result;
    }

    /**
     * Captcha plugin for current domain, or null if not configured.
     */
    public static function getCaptchaPlugin(): ?object
    {
        $id = authConfig::get('captcha_plugin');
        if (!$id) {
            return null;
        }
        $plugin_id = str_ends_with($id, '_plugin') ? substr($id, 0, -7) : $id;
        return self::loadPlugin($plugin_id, 'is_captcha');
    }

    public static function clearCache(): void
    {
        self::$cache = [];
    }

    // -------------------------------------------------------------------------

    private static function loadBuiltin(string $id): ?object
    {
        $map = [
            'email' => 'authEmailMethod',
            'waid'  => 'authWaidMethod',
            'phone' => 'authPhoneMethod',
        ];

        if (!isset($map[$id])) {
            return null;
        }

        $class = $map[$id];
        if (!class_exists($class)) {
            return null;
        }

        return new $class();
    }

    /**
     * @param string $plugin_id  Plugin directory name (without _plugin suffix)
     * @param string|null $required_flag  Required flag in plugin.php (e.g. 'is_auth', 'is_captcha')
     */
    private static function loadPlugin(string $plugin_id, ?string $required_flag = null): ?object
    {
        $cache_key = $plugin_id . ':' . ($required_flag ?? '');
        if (isset(self::$cache[$cache_key])) {
            return self::$cache[$cache_key];
        }

        $info = self::readPluginInfo($plugin_id);
        if ($info === null) {
            self::$cache[$cache_key] = null;
            return null;
        }

        if ($required_flag && empty($info[$required_flag])) {
            self::$cache[$cache_key] = null;
            return null;
        }

        $class = 'auth' . ucfirst($plugin_id) . 'Plugin';
        if (!class_exists($class)) {
            // Try autoloading from plugin's lib directory
            $lib_path = wa()->getAppPath("plugins/{$plugin_id}/lib/{$class}.class.php", 'auth');
            if (file_exists($lib_path)) {
                require_once $lib_path;
            }
        }

        if (!class_exists($class)) {
            self::$cache[$cache_key] = null;
            return null;
        }

        $plugin = new $class($info);

        // Verify interface matches declared flags
        if (!empty($info['is_auth']) && !($plugin instanceof authMethod)) {
            throw new waException("Plugin {$plugin_id} declared is_auth but does not implement authMethod");
        }
        if (!empty($info['is_challenge']) && !($plugin instanceof authChallenge)) {
            throw new waException("Plugin {$plugin_id} declared is_challenge but does not implement authChallenge");
        }
        if (!empty($info['is_guard']) && !($plugin instanceof authGuard)) {
            throw new waException("Plugin {$plugin_id} declared is_guard but does not implement authGuard");
        }
        if (!empty($info['is_captcha']) && !($plugin instanceof authCaptcha)) {
            throw new waException("Plugin {$plugin_id} declared is_captcha but does not implement authCaptcha");
        }

        self::$cache[$cache_key] = $plugin;
        return $plugin;
    }

    private static function readPluginInfo(string $plugin_id): ?array
    {
        $config_path = wa()->getAppPath("plugins/{$plugin_id}/lib/config/plugin.php", 'auth');
        if (!file_exists($config_path)) {
            return null;
        }

        $info = (array)include($config_path);
        $info['id']     = $plugin_id;
        $info['app_id'] = 'auth';
        return $info;
    }
}
