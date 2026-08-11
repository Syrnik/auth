<?php

/**
 * A plugin that adds a block to the profile page (my/).
 *
 * Decision 7 of docs/adr/001-profile-config-boundaries.md: a plugin section is
 * not a separate contract — it is an ordinary authProfileSection, reached
 * through this one extra method on the plugin itself. Kept as an interface
 * rather than a default no-op on authPlugin because the flag that announces it
 * (has_profile_section in plugin.php, see README "Разработка плагинов") has to
 * be checkable without instantiating the plugin, exactly like is_guard and
 * is_captcha (authPluginManager::loadPlugin()) — a plugin that claims the flag
 * but does not implement this interface is a configuration error, not a
 * silent no-op.
 */
interface authProfileSectionProvider
{
    /**
     * This plugin's profile section, or null when it has none to offer right
     * now (e.g. a section that only appears once something is configured).
     *
     * $section_id is the id the section must render, save and be looked up
     * under — authPluginManager::getProfileSectionPlugins()'s own key, the
     * domain's config id for this plugin (login_methods / challenge_methods /
     * guard_plugins / captcha_plugin), not anything the plugin computes for
     * itself. Passed in rather than left to the plugin so a plugin has no way
     * to claim a core section's id (or another plugin's) and hijack its save
     * route — the id a section answers to is never the plugin's choice.
     */
    public function getProfileSection(string $section_id, ?waContact $contact = null): ?authProfileSection;
}
