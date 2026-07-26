<?php

class authFrontendMyAction extends waMyProfileAction
{
    public function execute()
    {
        parent::execute();

        // Ready-made page structure: groups in display order, each with its
        // available sections and their state already worked out. The theme
        // walks the array and renders it — no visibility logic in Smarty,
        // where the three config sources would drift apart between themes.
        $groups = authProfileSectionRegistry::getGroups($this->contact);
        $this->restoreFailedSave($groups);
        $this->view->assign('profile_groups', $groups);

        $this->setThemeTemplate('my.profile.html');
        if (!waRequest::isXMLHttpRequest()) {
            $this->setLayout(new authFrontendLayout());
        }
    }

    protected function getForm()
    {
        return authContactForm::fromForm(parent::getForm());
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
     */
    private function restoreFailedSave(array &$groups): void
    {
        $failed = wa()->getStorage()->getOnce(authFrontendMySaveController::FAILED_SAVE);
        if (!is_array($failed) || empty($failed['section'])) {
            return;
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
            return;
        }
    }
}
