<?php

/**
 * Shared logic for every per-domain backend settings screen (dashboard, login
 * methods, signup, recovery, captcha, guards, challenges): resolves which
 * domain is being edited, remembers it in a cookie for the bare app entry
 * point, and saves posted data by merging it into the domain's stored config
 * instead of overwriting it wholesale — each section only owns a slice of
 * the config, and several sections share the top-level 'plugin_settings' key
 * (keyed by plugin id), so that one key needs a one-level-deeper merge.
 */
abstract class authBackendDomainSettingsAction extends waViewAction
{
    public const DOMAIN_COOKIE = 'auth_app_domain';

    public function execute(): void
    {
        if (!waRequest::isXMLHttpRequest()) {
            $this->setLayout(new authDefaultLayout());
        }
        $this->setTemplate($this->getTemplateName());

        $domains = array_keys(wa()->getRouting()->getByApp('auth'));
        if (!$domains) {
            $this->view->assign([
                'domain'     => '',
                'no_domains' => true,
            ]);
            return;
        }

        $domain = waRequest::param('domain', '', 'string');
        if (!$domain || !in_array($domain, $domains, true)) {
            $domain = $domains[0];
        }

        // Refresh on every per-domain page view so the bare app entry point
        // (no domain in the URL) can redirect into the last-visited domain.
        wa()->getResponse()->setCookie(self::DOMAIN_COOKIE, $domain, time() + 365 * 86400, null, '', false, true);

        $saved = false;
        if (waRequest::method() === 'post') {
            $this->save($domain);
            $saved = true;
        }

        $config = authConfig::getMerged($domain);

        $this->view->assign(array_merge([
            'domain'     => $domain,
            'no_domains' => false,
            'config'     => $config,
            'saved'      => $saved,
        ], $this->getViewData($config, $domain)));
    }

    abstract protected function getUrlSegment(): string;

    abstract protected function getTemplateName(): string;

    /**
     * Extra template vars for this section's form (available methods,
     * captchas, guards, ...). Default: nothing extra (e.g. Dashboard).
     */
    protected function getViewData(array $config, string $domain): array
    {
        return [];
    }

    /**
     * Config keys this section's POST contributes, merged into the domain's
     * stored config by save(). Default: nothing to save (e.g. Dashboard).
     */
    protected function collectSectionData(string $domain, array $current): array
    {
        return [];
    }

    private function save(string $domain): void
    {
        $config_path = wa()->getConfig()->getConfigPath('config.php', true, 'auth');
        $existing    = file_exists($config_path) ? (array)include($config_path) : [];
        $domains_cfg = (isset($existing['domains']) && is_array($existing['domains'])) ? $existing['domains'] : [];
        $current     = (isset($domains_cfg[$domain]) && is_array($domains_cfg[$domain])) ? $domains_cfg[$domain] : [];

        foreach ($this->collectSectionData($domain, $current) as $key => $value) {
            if ($key === 'plugin_settings') {
                // Login/Captcha/Guards/Challenges all write here, under different
                // plugin ids — merge one level deep instead of replacing wholesale.
                $current['plugin_settings'] = array_replace((array)($current['plugin_settings'] ?? []), (array)$value);
            } else {
                $current[$key] = $value;
            }
        }
        $domains_cfg[$domain] = $current;

        waUtils::varExportToFile(['domains' => $domains_cfg], $config_path);
        authConfig::clearCache();

        if (!waRequest::isXMLHttpRequest()) {
            wa()->getResponse()->redirect($this->getSectionUrl($domain) . '?saved=1');
        }
    }

    protected function getSectionUrl(string $domain): string
    {
        $segment = $this->getUrlSegment();
        return wa()->getAppUrl('auth', true) . 'settings/' . urlencode($domain) . '/' . ($segment ? $segment . '/' : '');
    }

    /**
     * All installed plugins implementing a given interface (authMethod,
     * authGuard, authCaptcha, authChallenge): [plugin_id => instance]
     */
    protected function getPluginInstancesOf(string $interface): array
    {
        $result = [];
        $plugins_path = wa()->getAppPath('plugins', 'auth');
        if (!is_dir($plugins_path)) {
            return $result;
        }
        foreach (scandir($plugins_path) as $dir) {
            if ($dir[0] === '.' || !is_dir($plugins_path . '/' . $dir)) {
                continue;
            }
            $plugin = authPluginManager::get($dir . '_plugin');
            if ($plugin instanceof $interface && $plugin instanceof authPlugin) {
                $result[$dir] = $plugin;
            }
        }
        return $result;
    }

    /**
     * Runs each plugin's own POST data through its prepareSettings().
     * Settings are kept even for currently disabled plugins, so toggling
     * a guard off and on does not lose its rules.
     *
     * For multi_instance plugins POST carries one block per named instance
     * (plugin_settings[plugin][instance_key][field]); each block goes through
     * prepareSettings() separately. An instance absent from POST is deleted —
     * the screen always renders every existing instance, so absence means
     * the admin removed it.
     */
    protected function collectPluginSettings(array $plugins, array $post_settings): array
    {
        $result = [];
        foreach ($plugins as $id => $plugin) {
            if (!isset($post_settings[$id]) || !is_array($post_settings[$id])) {
                continue;
            }
            if (empty($plugin->getInfo()['multi_instance'])) {
                $result[$id] = $plugin->prepareSettings($post_settings[$id]);
                continue;
            }
            $instances = [];
            foreach ($post_settings[$id] as $key => $values) {
                $key = strtolower(trim((string)$key));
                if (!is_array($values) || !preg_match('~^[a-z0-9][a-z0-9_-]*$~', $key)) {
                    continue;
                }
                $instances[$key] = $plugin->prepareSettings($values);
            }
            if ($instances) {
                $result[$id] = $instances;
            }
        }
        return $result;
    }
}
