<?php

abstract class authPlugin extends waPlugin
{
    /**
     * Named instance key when this plugin is enabled several times with
     * different settings (multi_instance => true in plugin.php): config id
     * 'oidc_plugin:gitlab' loads this plugin with instance 'gitlab'.
     * Null for regular single-slot plugins — everything then behaves as before.
     */
    protected ?string $instance = null;

    public function __construct($info)
    {
        if (isset($info['instance'])) {
            $this->instance = (string)$info['instance'];
        }
        parent::__construct($info);
    }

    public function getInstance(): ?string
    {
        return $this->instance;
    }

    /**
     * Config/method id of this plugin object as used in login_methods and
     * the callback route: 'oidc_plugin' or 'oidc_plugin:gitlab'.
     */
    public function getMethodId(): string
    {
        return $this->id . '_plugin' . ($this->instance !== null ? ':' . $this->instance : '');
    }

    /**
     * For a named instance, a per-instance 'name' domain setting (when the
     * plugin defines such a control) wins over the plugin's distribution
     * name, so two instances of the same plugin are distinguishable
     * on the login page.
     */
    public function getName()
    {
        if ($this->instance !== null) {
            $name = $this->getDomainSettings()['name'] ?? '';
            if (is_string($name) && $name !== '') {
                return $name;
            }
        }
        return parent::getName();
    }

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
     * For a named instance — only that instance's settings slice.
     * Not to be confused with waPlugin::getSettings(), which is global
     * (stored in wa_app_settings without a domain dimension).
     */
    public function getDomainSettings(string $domain = null): array
    {
        return authConfig::getPluginSettings($this->id, $domain, $this->instance);
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
