<?php

declare(strict_types=1);

class authFrontendMyAction extends waViewAction
{
    /**
     * Rendered partial an XHR asked for, or null when this request is an
     * ordinary page view. See getRequestedSection() and display().
     *
     * @var string|null
     */
    private ?string $fragment = null;

    /** @var waContact whose profile is being shown. */
    private waContact $contact;

    public function execute()
    {
        // The only address this page writes through is my/save/<section>/ —
        // see docs/adr/001-profile-config-boundaries.md, decision 5. A POST here
        // used to fall through to waMyProfileAction::saveFromPost(), a second
        // write path bypassing every section rule (confirmation, current-password
        // check, prepareData()/prepareForStorage(), logging). Closed by AUTH-85.
        if (waRequest::method() === 'post') {
            throw new waException(_w('This address does not accept form submissions. Use my/save/<section>/ instead.'), 405);
        }

        $this->contact = wa()->getUser();

        $this->view->assign('saved', boolval(wa()->getStorage()->getOnce('my/profile/updated')));

        $this->setThemeTemplate('my.profile.html');
        if (!waRequest::isXMLHttpRequest()) {
            $this->setLayout(new authFrontendLayout());
        }

        $section_id = waRequest::get('section', '', 'string');
        $requested  = $section_id === '' ? null : $this->getRequestedSection($section_id);

        // JS asking for one section gets that section alone — the very partial
        // the page is built out of, so the markup a script puts on the page and
        // the markup a reload produces cannot drift apart. The page template
        // still runs (display() renders it before we answer), and with no groups
        // assigned it comes out empty and costs nothing.
        if ($section_id !== '' && waRequest::isXMLHttpRequest()) {
            if (!$requested) {
                // A page may shrug a stale link off and just show the profile;
                // a fragment may not. Answering 200 with the whole page would
                // have the script paste that page into one section — so this is
                // the same 404 the save endpoint gives, and the script falls
                // back to following the link.
                throw new waException(_w('Profile section not found.'), 404);
            }

            $this->fragment = $requested['section']->render($requested['mode'], $requested['index']);
            $this->view->assign('profile_groups', []);
            return;
        }

        // Ready-made page structure: groups in display order, each with its
        // available sections and their state already worked out. The theme
        // walks the array and renders it — no visibility logic in Smarty,
        // where the three config sources would drift apart between themes.
        $groups   = authProfileSectionRegistry::getGroups($this->contact);
        $restored = $this->restoreFailedSave($groups);

        // A restored failure outranks ?section=: the visitor was redirected here
        // by it, and it carries submitted values and errors that the URL knows
        // nothing about and cannot reproduce.
        if ($requested && $requested['id'] !== $restored) {
            $groups = $this->openSection($groups, $requested);
        }

        $this->view->assign('profile_groups', $groups);
    }

    /**
     * Answers an XHR with the requested partial instead of the page.
     *
     * Deliberately raw HTML rather than a JSON envelope: this is a GET for one
     * representation of one section, and there is nothing to report about it
     * beyond the markup. The save endpoint answers in JSON because a POST also
     * has a status and errors to tell (authFrontendMySaveController::display()).
     */
    public function display($clear_assign = true)
    {
        $html = parent::display(false);

        return $this->fragment === null ? $html : $this->fragment;
    }

    // -------------------------------------------------------------------------

    /**
     * The section named by the query and the mode to show it in:
     * my/?section=<id>&mode=view|edit[&index=N].
     *
     * One address serves both readers. Without JS it is an ordinary link to the
     * profile page with that section opened; fetched by JS, the same URL answers
     * with the section alone — so the markup never has to carry a second address
     * for the script, and the two paths cannot point at different things.
     *
     * An unknown id, a section this domain does not offer and a section not
     * implemented yet all mean the same here: nothing is opened, and the page is
     * the plain profile. A link that outlived a config change is stale, not
     * broken, and answering a GET with a 404 would be a worse page. An XHR after
     * that same section does get the 404 — see execute().
     *
     * @return array|null ['id' => string, 'section' => authProfileSection, 'mode' => string, 'index' => int|null]
     */
    private function getRequestedSection(string $id): ?array
    {
        $section = authProfileSectionRegistry::getSection($id, $this->contact);
        if (!$section) {
            return null;
        }

        // Anything but an explicit 'edit' is the page's normal state, which is
        // also what the Cancel link asks for.
        $mode = waRequest::get('mode', authProfileSection::MODE_VIEW, 'string');
        if ($mode !== authProfileSection::MODE_EDIT) {
            $mode = authProfileSection::MODE_VIEW;
        }

        return [
            'id'      => $id,
            'section' => $section,
            'mode'    => $mode,
            'index'   => $this->getRequestedIndex($section),
        ];
    }

    /**
     * Which value of a multi-value section the URL is about, or null.
     *
     * A single-value section always gets null, per the contract. Unlike the save
     * endpoint this does not reject a stray index: a GET writes nothing, so the
     * worst a malformed one can do is open the section the visitor asked for.
     */
    private function getRequestedIndex(authProfileSection $section): ?int
    {
        if (!$section->isMultiple()) {
            return null;
        }

        $index = waRequest::get('index');

        return (is_scalar($index) && preg_match('/^\d+$/', (string)$index)) ? (int)$index : null;
    }

    /**
     * Re-renders one section of the page in the requested mode, leaving the rest
     * as the registry built it.
     *
     * @return array the groups, with that section's html replaced
     */
    private function openSection(array $groups, array $requested): array
    {
        foreach ($groups as $group_id => $group) {
            if (!isset($group['sections'][$requested['id']]['section'])) {
                continue;
            }

            /** @var authProfileSection $section */
            $section = $group['sections'][$requested['id']]['section'];

            $groups[$group_id]['sections'][$requested['id']]['html'] = $section->render(
                $requested['mode'],
                $requested['index']
            );
            break;
        }

        return $groups;
    }

    /**
     * Without JS a failed section save answers with a redirect back here
     * (authFrontendMySaveController::respondFailure()), so this request has to
     * pick that failure up from the session: the section it happened in is
     * shown in edit mode, holding the submitted values and the errors, instead
     * of the view-mode partial the registry rendered.
     *
     * Read once — a reload afterwards is a fresh look at the profile, not the
     * failure again.
     *
     * @return string|null id of the section restored, or null when there was none
     */
    private function restoreFailedSave(array &$groups): ?string
    {
        $failed = wa()->getStorage()->getOnce(authFrontendMySaveController::FAILED_SAVE);
        if (!is_array($failed) || empty($failed['section'])) {
            return null;
        }

        $section_id = (string)$failed['section'];
        foreach ($groups as $group_id => $group) {
            // A section that stopped being available since the failed POST is
            // not in the groups at all, and there is nothing to restore.
            if (!isset($group['sections'][$section_id]['section'])) {
                continue;
            }

            /** @var authProfileSection $section */
            $section = $group['sections'][$section_id]['section'];
            $index   = isset($failed['index']) ? (int)$failed['index'] : null;

            $section->restoreFailedSave(
                (array)ifset($failed, 'data', []),
                (array)ifset($failed, 'errors', []),
                $index
            );

            $groups[$group_id]['sections'][$section_id]['html'] = $section->render(
                authProfileSection::MODE_EDIT,
                $index
            );

            return $section_id;
        }

        return null;
    }
}
