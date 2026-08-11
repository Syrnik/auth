<?php

/**
 * Base for a profile section (my/) contributed by a plugin — see decision 7 of
 * docs/adr/001-profile-config-boundaries.md and authProfileSectionProvider.
 *
 * Everything authProfileSectionBase already does (identity, error collection,
 * render() itself) is reused as is. What differs from a core section:
 *
 *   - the id is handed in by the registry (the domain's own config id: 'foo_plugin',
 *     'oidc_plugin:gitlab'), not declared as a static property on the class —
 *     the whole point of decision 7 is that the id a plugin section renders
 *     under is not the plugin's to choose;
 *   - the partial has nowhere to fall back to in themes/default/ of this app,
 *     because it does not belong to this app — see getTemplatePath() below.
 */
abstract class authProfileSectionPlugin extends authProfileSectionBase
{
    /** @var authPlugin */
    protected $plugin;

    // authProfileSectionBase already declares $id, as a static per-class
    // property core sections set once on the class; a plugin section's id is
    // per-instance (the registry assigns it), so it is kept under a different
    // name rather than narrowing the inherited property's visibility.
    /** @var string section id assigned by the registry, see getId() */
    private $section_id;

    public function __construct(authPlugin $plugin, string $section_id, ?waContact $contact = null)
    {
        parent::__construct($contact);
        $this->plugin     = $plugin;
        $this->section_id = $section_id;
    }

    public function getId(): string
    {
        return $this->section_id;
    }

    /**
     * authProfileSectionBase::getGroup() looks the id up in the core map and
     * falls back to GROUP_PROFILE, which is the wrong default for a plugin
     * section (a plugin id is never in that map) — most plugin sections are
     * about credentials, so authorization is the sane default. A plugin that
     * wants the other group overrides this itself; getGroup() is already part
     * of the authProfileSection contract.
     */
    public function getGroup(): string
    {
        return authProfileSectionRegistry::GROUP_AUTHORIZATION;
    }

    // -------------------------------------------------------------------------

    /**
     * Same lookup as authProfileSectionBase::getTemplatePath() with one more
     * fallback: a plugin ships no themes/default/ of its own, so after the
     * active theme (which still gets first refusal — a theme may override a
     * plugin's partial the same way it overrides a core one) this looks in the
     * plugin's own templates/ directory via authPlugin::getTemplatePath(),
     * which already builds that path and checks it exists.
     *
     * The id is sanitized for the filename because a multi-instance plugin's
     * config id carries a ':' ('oidc_plugin:gitlab'), which is not a legal
     * filename character on every filesystem this runs on. The same
     * sanitized form is used for both the theme lookup and the plugin lookup,
     * so the two never disagree about what file they mean.
     */
    protected function getTemplatePath(string $mode): ?string
    {
        $safe_id = str_replace(':', '-', $this->getId());
        $file    = 'my.profile.'.$safe_id.'.'.$mode.'.html';

        $theme_id = waRequest::getTheme();
        if ($theme_id) {
            try {
                $theme = new waTheme($theme_id, 'auth');
                $path  = $theme->path.'/'.$file;
                if (file_exists($path)) {
                    return $path;
                }
            } catch (waException $e) {
                // Broken or missing theme: fall through to the plugin's own partial.
            }
        }

        return $this->plugin->getTemplatePath('my.profile.'.$mode);
    }

    protected function getTemplateVars(string $mode, ?int $index = null): array
    {
        return parent::getTemplateVars($mode, $index) + [
            'plugin' => $this->plugin,
        ];
    }
}
