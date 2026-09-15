<?php

class authBackendCaptchaAction extends authBackendDomainSettingsAction
{
    protected function getUrlSegment(): string
    {
        return 'captcha';
    }

    protected function getTemplateName(): string
    {
        return 'BackendCaptcha';
    }

    protected function getViewData(array $config, string $domain): array
    {
        return [
            'available_captchas'        => $this->getAvailableCaptchas($domain),
            'available_throttle_stores' => $this->getAvailableThrottleStores($domain),
        ];
    }

    protected function collectSectionData(string $domain, array $current): array
    {
        $post = waRequest::post();

        $plugin_settings = array_replace(
            $this->collectPluginSettings($this->getCaptchaPluginInstances(), (array)($post['plugin_settings'] ?? [])),
            $this->collectPluginSettings($this->getThrottleStorePluginInstances(), (array)($post['plugin_settings'] ?? []))
        );

        return [
            'captcha_plugin'  => (string)($post['captcha_plugin'] ?? ''),
            'captcha_mode'    => $this->prepareCaptchaMode((string)($post['captcha_mode'] ?? '')),
            'captcha_after_n' => $this->preparePositiveInt($post['captcha_after_n'] ?? null, 3, 1),

            'throttle_enabled'        => !empty($post['throttle_enabled']),
            'throttle_login_attempts' => $this->preparePositiveInt($post['throttle_login_attempts'] ?? null, 5, 1),
            'throttle_login_window'   => $this->preparePositiveInt($post['throttle_login_window'] ?? null, 900, 1, authThrottleDbStore::RETENTION_SECONDS),
            'throttle_ip_attempts'    => $this->preparePositiveInt($post['throttle_ip_attempts'] ?? null, 30, 1),
            'throttle_ip_window'      => $this->preparePositiveInt($post['throttle_ip_window'] ?? null, 900, 1, authThrottleDbStore::RETENTION_SECONDS),
            'throttle_delay'          => $this->preparePositiveInt($post['throttle_delay'] ?? null, 2, 0),
            'throttle_lockout'        => $this->preparePositiveInt($post['throttle_lockout'] ?? null, 0, 0, authThrottleDbStore::RETENTION_SECONDS),
            'throttle_store'          => (string)($post['throttle_store'] ?? ''),

            'plugin_settings' => $plugin_settings,
        ];
    }

    private function prepareCaptchaMode(string $mode): string
    {
        return in_array($mode, ['off', 'always', 'after_n'], true) ? $mode : 'always';
    }

    /**
     * All throttle-related numbers are non-negative integers typed in from a
     * plain text field — clamp rather than reject, same spirit as
     * authGcaptchaPlugin::prepareSettings()'s v3_threshold handling: a
     * malformed value falls back to something sane instead of a form error
     * that leaves the whole section unsaved.
     *
     * $max matters for more than tidiness on the two window fields and on
     * lockout: authThrottleDbStore's lazy sweep assumes no window_start is
     * ever older than RETENTION_SECONDS, which only holds if no configured
     * window/lockout exceeds it — an unbounded window would let the sweep
     * delete a counter's row before the very hit() that just wrote it
     * returns (see authThrottleDbStore's own docblock).
     */
    private function preparePositiveInt($value, int $default, int $min, ?int $max = null): int
    {
        if ($value === null || $value === '') {
            return $default;
        }
        $int = (int)$value;
        if ($int < $min) {
            return $min;
        }
        if ($max !== null && $int > $max) {
            return $max;
        }
        return $int;
    }

    /**
     * Installed throttle-store plugins with their per-domain settings
     * controls: [plugin_id => ['name' => ..., 'controls' => [...]]] — the
     * throttle_store counterpart to getAvailableCaptchas() above.
     */
    private function getAvailableThrottleStores(string $domain): array
    {
        $stores = [];
        foreach ($this->getThrottleStorePluginInstances() as $dir => $plugin) {
            $stores[$dir] = [
                'name'     => $plugin->getInfo()['name'] ?? $dir,
                'controls' => $plugin->getSettingsControls(authConfig::getPluginSettings($dir, $domain)),
            ];
        }
        return $stores;
    }

    /**
     * All installed throttle-store plugins: [plugin_id => authPlugin&authThrottleStore instance]
     */
    private function getThrottleStorePluginInstances(): array
    {
        return $this->getPluginInstancesOf(authThrottleStore::class);
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
