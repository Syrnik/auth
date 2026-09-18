<?php

/**
 * "Password recovery" settings screen. recovery_channels lists
 * authRecoveryProvider ids the domain accepts — see
 * docs/adr/004-recovery-channels.md.
 *
 * Order is presentation order, not a reorderable list: checkboxes are
 * submitted in the DOM order the template renders them
 * (getAvailableProviders()'s own order — built-ins first, plugins after), so
 * collectRecoveryChannels() only needs to filter what was posted, the same
 * way the "Login" screen's login_methods checkboxes already work.
 */
class authBackendRecoveryAction extends authBackendDomainSettingsAction
{
    protected function getUrlSegment(): string
    {
        return 'recovery';
    }

    protected function getTemplateName(): string
    {
        return 'BackendRecovery';
    }

    protected function getViewData(array $config, string $domain): array
    {
        return [
            'available_providers' => $this->getAvailableProviders(),
        ];
    }

    protected function collectSectionData(string $domain, array $current): array
    {
        $post = waRequest::post();

        return [
            'recovery_enabled'  => !empty($post['recovery_enabled']),
            'rememberme'        => !empty($post['rememberme']),
            'recovery_channels' => $this->collectRecoveryChannels((array)($post['recovery_channels'] ?? [])),
        ];
    }

    /**
     * Every provider the admin can enable: the two built-ins, always listed
     * first and in a stable order, then installed authRecoveryProvider
     * plugins in directory-scan order. Returns [id => ['name' => ...]].
     */
    private function getAvailableProviders(): array
    {
        $providers = [
            'email' => ['name' => _w('Email')],
            'phone' => ['name' => _w('Phone (SMS code)')],
        ];

        foreach ($this->getPluginInstancesOf(authRecoveryProvider::class) as $dir => $plugin) {
            $providers[$dir . '_plugin'] = ['name' => $plugin->getInfo()['name'] ?? $dir];
        }

        return $providers;
    }

    /**
     * Posted ids, filtered to ones that are actually available — a stale or
     * forged id in POST is dropped rather than saved. The posted order is
     * kept as-is: it is already the presentation order (see class docblock),
     * not something this method needs to reconstruct.
     */
    private function collectRecoveryChannels(array $posted): array
    {
        $available = array_keys($this->getAvailableProviders());
        return array_values(array_intersect($posted, $available));
    }
}
