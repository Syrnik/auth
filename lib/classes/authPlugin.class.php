<?php

abstract class authPlugin extends waPlugin
{
    /**
     * Absolute path to a template in the plugin's templates/ directory.
     * Returns null if the template does not exist.
     */
    public function getTemplatePath(string $part): ?string
    {
        $path = $this->path . '/templates/' . $part . '.html';
        return file_exists($path) ? $path : null;
    }

    /**
     * This plugin's per-domain settings from the auth app config.
     * Not to be confused with waPlugin::getSettings(), which is global
     * (stored in wa_app_settings without a domain dimension).
     */
    public function getDomainSettings(string $domain = null): array
    {
        return authConfig::getPluginSettings($this->id, $domain);
    }

    /**
     * Controls to render on the backend per-domain settings screen.
     * [field_id => ['label' => ..., 'type' => 'text'|'textarea', 'value' => ..., 'hint' => ...]]
     * $settings — current per-domain settings of this plugin.
     */
    public function getSettingsControls(array $settings): array
    {
        return [];
    }

    /**
     * Convert posted control values into the array stored in the domain config.
     */
    public function prepareSettings(array $post): array
    {
        return array_map('strval', $post);
    }
}
