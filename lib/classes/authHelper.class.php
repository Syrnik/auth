<?php

class authHelper
{
    public static function getLoginUrl(): string
    {
        return wa()->getRouteUrl('auth/frontend/login', [], true);
    }

    public static function getRegisterUrl(): string
    {
        return wa()->getRouteUrl('auth/frontend/register', [], true);
    }

    public static function getLogoutUrl(): string
    {
        return wa()->getRouteUrl('auth/frontend/logout', [], true);
    }

    public static function getRecoveryUrl(): string
    {
        return wa()->getRouteUrl('auth/frontend/recovery', [], true);
    }

    public static function getMyUrl(): string
    {
        return wa()->getRouteUrl('auth/frontend/my', [], true);
    }

    public static function getChallengeUrl(): string
    {
        return wa()->getRouteUrl('auth/frontend/challenge', [], true);
    }

    public static function getCallbackUrl(string $plugin_id): string
    {
        return wa()->getRouteUrl('auth/frontend/callback', ['method_id' => $plugin_id], true);
    }

    public static function isLoggedIn(): bool
    {
        return wa()->getUser()->isAuth();
    }

    public static function getUser(): waContact
    {
        return wa()->getUser();
    }

    /**
     * Returns HTML of the captcha widget, or empty string if captcha is not configured.
     */
    public static function getCaptchaWidget(): string
    {
        $plugin = authPluginManager::getCaptchaPlugin();
        return $plugin ? $plugin->renderWidget() : '';
    }

    /**
     * Returns true if at least one active login method supports password recovery.
     */
    public static function hasRecovery(): bool
    {
        if (!authConfig::get('recovery_enabled')) {
            return false;
        }
        foreach (authPluginManager::getEnabled() as $method) {
            if (self::methodHasRecovery($method)) {
                return true;
            }
        }
        return false;
    }

    public static function isRememberMeEnabled(): bool
    {
        return (bool)authConfig::get('rememberme', false);
    }

    /**
     * Returns list of OAuth providers for display on the login page.
     * Each item: ['id' => string, 'name' => string, 'auth_url' => string]
     */
    public static function getOAuthProviders(): array
    {
        $providers = [];
        foreach (authPluginManager::getEnabled() as $id => $method) {
            if (!self::methodIsOAuth($method)) {
                continue;
            }
            // WAID built-in uses its own URL (standard Webasyst oauth.php flow).
            // OAuth plugins use our callback route.
            if ($method instanceof authWaidMethod) {
                $auth_url = $method->getWaidUrl();
            } else {
                $auth_url = self::getCallbackUrl($id);
            }
            if (!$auth_url) {
                continue;
            }
            $providers[] = [
                'id'       => $id,
                'name'     => self::methodName($method),
                'auth_url' => $auth_url,
            ];
        }
        return $providers;
    }

    /**
     * CSRF token value from cookie (for use in hidden form fields).
     */
    public static function getCsrfToken(): string
    {
        return waRequest::cookie('_csrf', '');
    }

    // -------------------------------------------------------------------------

    private static function methodHasRecovery($method): bool
    {
        if ($method instanceof authPlugin) {
            return (bool)($method->getInfo()['has_recovery'] ?? false);
        }
        if ($method instanceof authBuiltinMethod) {
            // built-in methods declare this as a class constant or static property
            return defined(get_class($method).'::HAS_RECOVERY')
                ? (bool)constant(get_class($method).'::HAS_RECOVERY')
                : false;
        }
        return false;
    }

    private static function methodIsOAuth($method): bool
    {
        if ($method instanceof authPlugin) {
            return ($method->getInfo()['auth_type'] ?? '') === 'oauth';
        }
        if ($method instanceof authBuiltinMethod) {
            return defined(get_class($method).'::AUTH_TYPE')
                && constant(get_class($method).'::AUTH_TYPE') === 'oauth';
        }
        return false;
    }

    private static function methodName($method): string
    {
        if ($method instanceof authPlugin) {
            return $method->getName();
        }
        if ($method instanceof authBuiltinMethod) {
            return method_exists($method, 'getName') ? $method->getName() : $method->getId();
        }
        return '';
    }
}
