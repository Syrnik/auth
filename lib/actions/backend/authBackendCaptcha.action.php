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
            'available_captchas' => $this->getAvailableCaptchas($domain),
        ];
    }

    protected function collectSectionData(string $domain, array $current): array
    {
        $post = waRequest::post();

        return [
            'captcha_plugin'  => (string)($post['captcha_plugin'] ?? ''),
            'plugin_settings' => $this->collectPluginSettings(
                $this->getCaptchaPluginInstances(),
                (array)($post['plugin_settings'] ?? [])
            ),
        ];
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
