<?php

class authPluginManager
{
    private static array $cache = [];

    /**
     * Get a method/plugin instance by its config ID.
     * 'email' → built-in authEmailMethod
     * 'github_plugin' → plugin from plugins/github/
     * 'oidc_plugin:gitlab' → named instance 'gitlab' of a multi_instance plugin plugins/oidc/
     */
    public static function get(string $id): ?object
    {
        [$id, $instance] = self::splitInstance($id);
        if (str_ends_with($id, '_plugin')) {
            return self::loadPlugin(substr($id, 0, -7), $instance);
        }
        return $instance === null ? self::loadBuiltin($id) : null;
    }

    /**
     * Split a config id into [id, instance_key]: 'oidc_plugin:gitlab' →
     * ['oidc_plugin', 'gitlab']; ids without ':' get a null instance.
     */
    public static function splitInstance(string $id): array
    {
        $pos = strpos($id, ':');
        if ($pos === false) {
            return [$id, null];
        }
        $instance = substr($id, $pos + 1);
        return [substr($id, 0, $pos), $instance === '' ? null : $instance];
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
            [$id, $instance] = self::splitInstance($id);
            $plugin_id = str_ends_with($id, '_plugin') ? substr($id, 0, -7) : $id;
            $info = self::readPluginInfo($plugin_id);
            if (!$info) {
                continue;
            }
            $flag = 'guard_' . $point;
            if (empty($info[$flag])) {
                continue;
            }
            $plugin = self::loadPlugin($plugin_id, $instance, 'is_guard');
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
        [$id, $instance] = self::splitInstance($id);
        $plugin_id = str_ends_with($id, '_plugin') ? substr($id, 0, -7) : $id;
        return self::loadPlugin($plugin_id, $instance, 'is_captcha');
    }

    /**
     * Plugins offering a profile section (my/) for the current domain, keyed
     * by the config id they are enabled under — 'github_plugin',
     * 'oidc_plugin:gitlab'. That key, not anything the plugin returns, is what
     * authProfileSectionRegistry uses as the section id: see
     * authProfileSectionProvider for why.
     *
     * Discovery follows is_guard/is_captcha: the flag (has_profile_section) is
     * read from plugin.php without instantiating the class. Checked against
     * the domain's own enabled-plugin lists — login_methods, challenge_methods,
     * guard_plugins, captcha_plugin — and nothing wider, or a plugin installed
     * but not enabled anywhere for this domain would show a block anyway
     * (decision 7 of docs/adr/001-profile-config-boundaries.md).
     *
     * Loaded through self::get(), the same entry point and the same cache as
     * every other role: loadPlugin() keys its cache on the required flag too,
     * and asking for 'has_profile_section' there would mint a second instance
     * of a plugin already loaded as a challenge/guard/etc — the section would
     * then hold state (e.g. a pending secret) the other half never sees.
     *
     * @return array config_id => authPlugin
     */
    public static function getProfileSectionPlugins(): array
    {
        $ids = array_unique(array_merge(
            authConfig::getLoginMethods(),
            authConfig::getChallengeMethods(),
            authConfig::getGuardPlugins(),
            array_filter([authConfig::get('captcha_plugin')])
        ));

        $result = [];
        foreach ($ids as $id) {
            [$base_id, ] = self::splitInstance($id);
            $plugin_id = str_ends_with($base_id, '_plugin') ? substr($base_id, 0, -7) : $base_id;
            $info = self::readPluginInfo($plugin_id);
            if (!$info || empty($info['has_profile_section'])) {
                continue;
            }
            $plugin = self::get($id);
            if ($plugin instanceof authProfileSectionProvider) {
                $result[$id] = $plugin;
            }
        }
        return $result;
    }

    public static function clearCache(): void
    {
        self::$cache = [];
    }

    /**
     * Runs $fn with $plugin's locale domain active, so any _wp() called
     * inside (directly, or from a template it renders) resolves against the
     * plugin's own catalog before falling back to the app's. Pairs with the
     * locale load in readPluginInfo() above — that makes the catalog
     * available, this makes _wp() look at it.
     *
     * Unlike waSystem::getPlugin($id, true), which pushes and never pops, the
     * plugin is popped in finally: several plugins load in one auth request
     * (login methods, guards, captcha), getActiveLocaleDomain() reads the top
     * of that stack, and a permanent push would leave shared msgids ('Cancel',
     * 'Disable') resolving against whichever plugin happened to load last.
     *
     * The domain is $plugin->getInfo()['id'] — the plugin's directory id, set
     * by readPluginInfo() — not $plugin->getId(): getId() is an authChallenge
     * interface method the plugin author implements for its own purposes
     * (totp's happens to equal the directory id, but nothing guarantees
     * that), while the locale directory is always keyed by the directory id.
     */
    public static function withPluginLocale(authPlugin $plugin, callable $fn)
    {
        $id = $plugin->getInfo()['id'] ?? null;
        if (!$id) {
            return $fn();
        }
        waSystem::pushActivePlugin($id, 'auth');
        try {
            return $fn();
        } finally {
            waSystem::popActivePlugin();
        }
    }

    /**
     * This app's own built-in form-based methods (not framework adapters, not
     * plugins) — id => class name. Single source of truth for both runtime
     * loading (loadBuiltin()) and the backend settings screen, so a new method
     * only needs to be added here once.
     */
    public static function getBuiltinFormMethods(): array
    {
        return [
            'email' => 'authEmailMethod',
            'login' => 'authLoginMethod',
            'phone' => 'authPhoneMethod',
        ];
    }

    /**
     * All framework-level OAuth adapters (VK, Facebook, Google, Webasyst ID, ...),
     * keyed by our method id, valued by the provider id used with wa()->getAuth().
     * For every id except 'waid' the two are identical; 'waid' is Webasyst ID's
     * short alias for provider id 'webasystID' (used throughout our routes already).
     * New adapters dropped into wa-system/auth/adapters/ appear here automatically.
     */
    public static function getSystemAdapters(): array
    {
        static $map = null;
        if ($map === null) {
            $map = ['waid' => waWebasystIDAuthAdapter::PROVIDER_ID];
            $path = wa()->getConfig()->getPath('system') . '/auth/adapters/';
            foreach ((is_dir($path) ? scandir($path) : []) as $f) {
                if (substr($f, -14) === 'Auth.class.php') {
                    $id = substr($f, 0, -14);
                    $map[$id] = $id;
                }
            }
        }
        return $map;
    }

    // -------------------------------------------------------------------------

    private static function loadBuiltin(string $id): ?object
    {
        $map = self::getBuiltinFormMethods();
        $map['waid'] = 'authWaidMethod';

        if (isset($map[$id])) {
            $class = $map[$id];
            return class_exists($class) ? new $class() : null;
        }

        $system_adapters = self::getSystemAdapters();
        if (isset($system_adapters[$id]) && $id !== 'waid') {
            return new authSocialMethod($id, $system_adapters[$id]);
        }

        return null;
    }

    /**
     * @param string $plugin_id  Plugin directory name (without _plugin suffix)
     * @param string|null $instance  Named instance key for multi_instance plugins ('oidc_plugin:gitlab' → 'gitlab')
     * @param string|null $required_flag  Required flag in plugin.php (e.g. 'is_auth', 'is_captcha')
     */
    private static function loadPlugin(string $plugin_id, ?string $instance = null, ?string $required_flag = null): ?object
    {
        $cache_key = $plugin_id . '|' . ($instance ?? '') . '|' . ($required_flag ?? '');
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

        // Instance-qualified ids are only valid for plugins that declared
        // multi_instance support; for everyone else the id is a typo.
        if ($instance !== null && empty($info['multi_instance'])) {
            self::$cache[$cache_key] = null;
            return null;
        }
        if ($instance !== null) {
            $info['instance'] = $instance;
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
        if (!empty($info['has_profile_section']) && !($plugin instanceof authProfileSectionProvider)) {
            throw new waException("Plugin {$plugin_id} declared has_profile_section but does not implement authProfileSectionProvider");
        }

        self::$cache[$cache_key] = $plugin;
        return $plugin;
    }

    /**
     * Reads plugin.php and enriches it the same way waSystem::getPlugin()
     * enriches $plugin_info for plugins loaded the framework's way
     * (wa-system/waSystem.class.php:1390) — img web path, build, translated
     * name/title/description. This manager doesn't call getPlugin() itself
     * (see class-level notes on loadPlugin()), so it has to redo this part
     * of the work, or those four things are simply lost for every auth
     * plugin. Order matters: the plugin's locale catalog has to be loaded
     * before _wd() can translate anything with it.
     */
    private static function readPluginInfo(string $plugin_id): ?array
    {
        $config_path = wa()->getAppPath("plugins/{$plugin_id}/lib/config/plugin.php", 'auth');
        if (!file_exists($config_path)) {
            return null;
        }

        $info = (array)include($config_path);
        $info['id']     = $plugin_id;
        $info['app_id'] = 'auth';

        if (isset($info['img'])) {
            $info['img'] = 'wa-apps/auth/plugins/' . $plugin_id . '/' . $info['img'];
        }

        $build_file = wa()->getAppPath("plugins/{$plugin_id}/lib/config/build.php", 'auth');
        if (file_exists($build_file)) {
            $info['build'] = include($build_file);
        } else {
            $info['build'] = waSystemConfig::isDebug() ? time() : 0;
        }

        // Domain is always 'auth_<directory id>', hardcoded rather than
        // derived from $info['app_id'] — a plugin.php declaring its own
        // app_id must not desync from what withPluginLocale() and
        // waSystem::pushActivePlugin($id, 'auth') resolve to. Same domain
        // regardless of instance, so every named instance of a
        // multi-instance plugin shares one catalog.
        $domain = 'auth_' . $plugin_id;
        $locale_path = wa()->getAppPath("plugins/{$plugin_id}/locale", 'auth');
        if (is_dir($locale_path)) {
            waLocale::load(wa()->getLocale(), $locale_path, $domain, false);
        }
        foreach (['name', 'title', 'description'] as $key) {
            if (isset($info[$key]) && is_string($info[$key]) && $info[$key] !== '') {
                $info[$key] = _wd($domain, $info[$key]);
            }
        }

        return $info;
    }
}
