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

    /** @var string[] every domain the auth app is routed on, filled by execute() */
    private array $domains = [];

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

        $this->domains = $domains;

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

    /**
     * Runs once, right after the domain's config file is written and the
     * cache cleared — a hook for work that must see the just-saved config
     * (AUTH-440: purging account links for a multi-instance connection the
     * admin just deleted). Default: nothing. Deliberately not folded into
     * collectSectionData(), which runs before the write and whose return
     * value only ever describes config to merge — this runs after, for
     * side effects the config write itself doesn't cover.
     */
    protected function afterSave(string $domain): void
    {
    }

    /**
     * Every domain the auth app is routed on — the same list execute() reads
     * this app's routing rules for. A subclass needs it to tell "this
     * connection is configured on another domain too" (AUTH-440's cross-
     * domain check, see authPlugin::getLinkSource()'s class-level note:
     * account links carry no domain dimension, only the connection config
     * does) from "it's only ever been on this one".
     *
     * @return string[]
     */
    protected function getDomains(): array
    {
        return $this->domains;
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

        $this->afterSave($domain);

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
     * prepareSettings() separately. Deletion is never inferred from an
     * instance being absent from POST — a truncated request (max_input_vars,
     * a JS failure) would then read identically to an admin who removed it,
     * and since AUTH-440 that removal also purges account links. $deleted
     * is the explicit, validated list from the caller (deleted_instances in
     * POST, checked against real instance keys before it gets here — see
     * authBackendLoginAction::collectSectionData()).
     *
     * $current_plugin_settings (this domain's stored plugin_settings, before
     * this save) is the fallback for a multi_instance plugin id: save()'s own
     * merge (array_replace() on the top-level plugin_settings, one level
     * deep) treats whatever this method returns for a plugin id as that id's
     * *entire* block, so an instance key present in storage but missing from
     * this POST — for any reason other than an explicit deletion — has to be
     * carried forward here, or it silently disappears the moment any other
     * instance of the same plugin is touched. This is the AUTH-440 "deleting
     * the last instance doesn't stick" bug in reverse: the single-slot branch
     * above doesn't need this, because save() leaves a plugin id it never
     * hears about alone entirely.
     *
     * @param array $plugins [plugin_id => authPlugin instance]
     * @param array $post_settings raw POST plugin_settings
     * @param array $current_plugin_settings this domain's plugin_settings before this save
     * @param array $deleted [plugin_id => [instance_key, ...]] confirmed deletions
     */
    protected function collectPluginSettings(
        array $plugins,
        array $post_settings,
        array $current_plugin_settings = [],
        array $deleted = []
    ): array {
        $result = [];
        foreach ($plugins as $id => $plugin) {
            if (empty($plugin->getInfo()['multi_instance'])) {
                if (!isset($post_settings[$id]) || !is_array($post_settings[$id])) {
                    continue;
                }
                $result[$id] = $plugin->prepareSettings($post_settings[$id]);
                continue;
            }

            $posted  = is_array($post_settings[$id] ?? null) ? $post_settings[$id] : [];
            $current = is_array($current_plugin_settings[$id] ?? null) ? $current_plugin_settings[$id] : [];

            // Current first, POST on top: an instance this request didn't
            // touch at all keeps its stored settings; one it did post
            // replaces its settings wholesale, same as before. Only then is
            // the explicit deletion list applied — deleting the last
            // instance of a plugin now correctly yields [], not the stale
            // block this fallback would otherwise resurrect.
            $instances = authPluginManager::filterInstanceBlocks(
                array_replace($current, $posted),
                $deleted[$id] ?? []
            );

            foreach ($instances as $key => $values) {
                if (!is_array($values)) {
                    unset($instances[$key]);
                    continue;
                }
                $instances[$key] = $plugin->prepareSettings($values);
            }

            $result[$id] = $instances;
        }
        return $result;
    }
}
