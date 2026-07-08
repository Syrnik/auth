<?php

class authBackendGuardsAction extends authBackendDomainSettingsAction
{
    protected function getUrlSegment(): string
    {
        return 'guards';
    }

    protected function getTemplateName(): string
    {
        return 'BackendGuards';
    }

    protected function getViewData(array $config, string $domain): array
    {
        return [
            'available_guards' => $this->getAvailableGuards($domain),
        ];
    }

    protected function collectSectionData(string $domain, array $current): array
    {
        $post   = waRequest::post();
        $guards = $this->getGuardPluginInstances();

        return [
            'guard_plugins'   => array_values(array_intersect(
                (array)($post['guard_plugins'] ?? []),
                array_keys($guards)
            )),
            'plugin_settings' => $this->collectPluginSettings(
                $guards,
                (array)($post['plugin_settings'] ?? [])
            ),
        ];
    }

    /**
     * Guard plugins the admin can enable for this domain, with their per-domain
     * settings controls: [id => ['name' => ..., 'controls' => [field_id => [...]]]]
     */
    private function getAvailableGuards(string $domain): array
    {
        $guards = [];
        foreach ($this->getGuardPluginInstances() as $id => $plugin) {
            $guards[$id] = [
                'name'     => $plugin->getName(),
                'controls' => $plugin->getSettingsControls(authConfig::getPluginSettings($id, $domain)),
            ];
        }
        return $guards;
    }

    /**
     * All installed guard plugins: [plugin_id => authPlugin&authGuard instance]
     */
    private function getGuardPluginInstances(): array
    {
        return $this->getPluginInstancesOf(authGuard::class);
    }
}
