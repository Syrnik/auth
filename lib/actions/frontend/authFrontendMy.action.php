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
        $this->view->assign('profile_groups', authProfileSectionRegistry::getGroups($this->contact));

        $this->setThemeTemplate('my.profile.html');
        if (!waRequest::isXMLHttpRequest()) {
            $this->setLayout(new authFrontendLayout());
        }
    }

    protected function getForm()
    {
        return authContactForm::fromForm(parent::getForm());
    }
}
