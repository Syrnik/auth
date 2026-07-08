<?php

class authBackendChallengesAction extends authBackendDomainSettingsAction
{
    protected function getUrlSegment(): string
    {
        return 'challenges';
    }

    protected function getTemplateName(): string
    {
        return 'BackendChallenges';
    }

    protected function getViewData(array $config, string $domain): array
    {
        return [
            'available_challenges' => $this->getAvailableChallenges($domain),
        ];
    }

    protected function collectSectionData(string $domain, array $current): array
    {
        $post       = waRequest::post();
        $challenges = $this->getChallengePluginInstances();

        return [
            'challenge_methods' => array_values(array_intersect(
                (array)($post['challenge_methods'] ?? []),
                // Unlike guard_plugins/captcha_plugin (lenient, suffix optional),
                // challenge_methods is resolved by authPluginManager::get(), the
                // same strict resolver login_methods uses — a plugin id there
                // MUST carry the '_plugin' suffix or it's mistaken for a builtin
                // method and silently resolves to null.
                array_map(fn($dir) => $dir . '_plugin', array_keys($challenges))
            )),
            'plugin_settings'   => $this->collectPluginSettings(
                $challenges,
                (array)($post['plugin_settings'] ?? [])
            ),
        ];
    }

    /**
     * Challenge (2FA) plugins the admin can enable for this domain, with their
     * per-domain settings controls. Entries are keyed '{dir}_plugin' — same
     * suffix convention as login methods — because challenge_methods, like
     * login_methods, is resolved by authPluginManager::get(), which requires
     * the suffix to tell a plugin id apart from a builtin method id.
     * Returns [method_id => ['name' => ..., 'plugin_id' => dir, 'controls' => [field_id => [...]]]]
     */
    private function getAvailableChallenges(string $domain): array
    {
        $challenges = [];
        foreach ($this->getChallengePluginInstances() as $dir => $plugin) {
            $challenges[$dir . '_plugin'] = [
                'name'      => $plugin->getName(),
                'plugin_id' => $dir,
                'controls'  => $plugin->getSettingsControls(authConfig::getPluginSettings($dir, $domain)),
            ];
        }
        return $challenges;
    }

    /**
     * All installed challenge plugins: [plugin_id => authPlugin&authChallenge instance]
     */
    private function getChallengePluginInstances(): array
    {
        return $this->getPluginInstancesOf(authChallenge::class);
    }
}
