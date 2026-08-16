<?php

/**
 * Everything a profile section does the same way regardless of where its data
 * lives: identity, template lookup, error collection. Sections built out of
 * contact fields extend authProfileSectionFields instead, which adds the form
 * machinery on top of this.
 *
 * Subclasses declare their identity in the static properties and implement the
 * two questions only they can answer — isAvailable() and isEmpty().
 */
abstract class authProfileSectionBase implements authProfileSection
{
    /** @var string section id, see authProfileSection::getId() */
    protected static $id = '';

    /** @var waContact */
    protected $contact;

    /** @var array field_id => list of error messages, from the last save() */
    protected $errors = [];

    public function __construct(?waContact $contact = null)
    {
        $this->contact = $contact ?: wa()->getUser();
    }

    public function getId(): string
    {
        return static::$id;
    }

    /**
     * Group placement is declared once, in the registry map, so that reordering
     * the page does not mean editing eight classes.
     */
    public function getGroup(): string
    {
        return authProfileSectionRegistry::getGroupId($this->getId())
            ?: authProfileSectionRegistry::GROUP_PROFILE;
    }

    public function isMultiple(): bool
    {
        return false;
    }

    public function getForm(?int $index = null): ?waContactForm
    {
        return null;
    }

    public function getRemovalLock(?int $index = null): ?string
    {
        return null;
    }

    public function getErrors(): array
    {
        return $this->errors;
    }

    /**
     * Stay on the profile. Only a section whose save leaves nothing to redraw
     * answers otherwise — see authProfileSection::getRedirectAfterSave().
     */
    public function getRedirectAfterSave(): ?string
    {
        return null;
    }

    /**
     * Errors are all a form-less section has to restore; the submitted values
     * of such a section are its own affair (an unlink is not a value the user
     * retypes). Sections built out of contact fields put the values back into
     * their form, see authProfileSectionFields.
     */
    public function restoreFailedSave(array $data, array $errors, ?int $index = null): void
    {
        $this->errors = $errors;
    }

    /**
     * Renders the section's partial for the mode, or an empty string when the
     * theme has no partial for it. Template vars are set for the duration of
     * the fetch and then restored, so a section never leaks state into the page
     * that embeds it.
     *
     * Rendered from two places: the page itself (authFrontendMyAction, already
     * past setThemeTemplate(), so the theme's locale domain is already active)
     * and the JSON save endpoint (authFrontendMySaveController), which renders
     * a section in isolation without ever calling setThemeTemplate(). Without
     * withThemeLocale() below, a _wp() msgid in the partial would resolve
     * against whatever domain (if any) happens to be active on that second
     * path — silently different from what the same partial shows on reload.
     */
    public function render(string $mode, ?int $index = null): string
    {
        $path = $this->getTemplatePath($mode);
        if (!$path) {
            return '';
        }

        $vars = $this->getTemplateVars($mode, $index);
        $view = wa()->getView();

        $saved = [];
        foreach (array_keys($vars) as $name) {
            $saved[$name] = $view->getVars($name);
        }

        $view->assign($vars);
        try {
            $html = $this->withThemeLocale(function () use ($view, $path) {
                return $view->fetch('file:'.$path);
            });
        } finally {
            foreach ($saved as $name => $value) {
                if ($value === null) {
                    $view->clearAssign($name);
                } else {
                    $view->assign($name, $value);
                }
            }
        }

        return $html;
    }

    // -------------------------------------------------------------------------

    /**
     * Partial of this section for the given mode: my.profile.{id}.{mode}.html,
     * taken from the active theme and falling back to the theme shipped with
     * the app, so a custom theme may override one section without copying all
     * of them.
     */
    protected function getTemplatePath(string $mode): ?string
    {
        $file = 'my.profile.'.$this->getId().'.'.$mode.'.html';

        $theme = $this->resolveTheme();
        if ($theme) {
            $path = $theme->path.'/'.$file;
            if (file_exists($path)) {
                return $path;
            }
        }

        $path = wa()->getAppPath('themes/default/'.$file, 'auth');
        return file_exists($path) ? $path : null;
    }

    /**
     * The active theme (from the request), or null when there isn't one /
     * it's broken. Shared by getTemplatePath() and withThemeLocale() so both
     * agree on which theme is in play — see authProfileSectionPlugin's
     * override, which needs the same instance for the same reason.
     */
    protected function resolveTheme(): ?waTheme
    {
        $theme_id = waRequest::getTheme();
        if (!$theme_id) {
            return null;
        }

        try {
            return new waTheme($theme_id, 'auth');
        } catch (waException $e) {
            // Broken or missing theme: caller falls back to the shipped one.
            return null;
        }
    }

    /**
     * Runs $fn with the active theme's locale domain(s) pushed, mirroring what
     * waView::setThemeTemplate() -> setLocales() does for a page rendered the
     * ordinary way (wa-system/view/waView.class.php). A no-op when a domain is
     * already active — the page path already did this once via
     * setThemeTemplate(), and pushing again would just duplicate the same
     * domain in the lookup chain.
     */
    protected function withThemeLocale(callable $fn)
    {
        if (wa()->getActiveThemes()) {
            return $fn();
        }

        $theme = $this->resolveTheme();
        if (!$theme) {
            return $fn();
        }

        $locale = wa()->getLocale();
        $domains = [$theme->locale_domain];
        waLocale::load($locale, $theme->locale_path, $theme->locale_domain, false);

        $parent_theme = $theme->parent_theme;
        if ($parent_theme instanceof waTheme) {
            $domains[] = $parent_theme->locale_domain;
            waLocale::load($locale, $parent_theme->locale_path, $parent_theme->locale_domain, false);
        }

        wa()->pushActiveTheme($domains);
        try {
            return $fn();
        } finally {
            wa()->popActiveTheme($domains);
        }
    }

    /**
     * @return array template var name => value
     */
    protected function getTemplateVars(string $mode, ?int $index = null): array
    {
        return [
            'section'   => $this,
            'contact'   => $this->contact,
            'mode'      => $mode,
            'index'     => $index,
            'form'      => $this->getForm($index),
            'errors'    => $this->errors,
            'save_url'  => $this->getSaveUrl(),
            'edit_url'  => $this->getModeUrl(self::MODE_EDIT, $index),
            'view_url'  => $this->getModeUrl(self::MODE_VIEW, $index),
        ];
    }

    /**
     * Where this section's form posts to, see authFrontendMySaveController.
     */
    protected function getSaveUrl(): string
    {
        return authHelper::getMySaveUrl($this->getId());
    }

    /**
     * The profile page with this section shown in the given mode, which is also
     * what JS fetches to redraw the section on its own (authFrontendMyAction).
     *
     * Built here rather than in Smarty so that the two readers of this address
     * cannot disagree: a template composing it by hand would have to know about
     * $index, and every theme would have to know again.
     */
    protected function getModeUrl(string $mode, ?int $index = null): string
    {
        $params = ['section' => $this->getId(), 'mode' => $mode];
        if ($index !== null) {
            $params['index'] = $index;
        }

        return authHelper::getMyUrl().'?'.http_build_query($params);
    }

    protected function addError(string $field_id, string $message): void
    {
        $this->errors[$field_id][] = $message;
    }
}
