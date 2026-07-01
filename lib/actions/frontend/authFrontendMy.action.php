<?php

class authFrontendMyAction extends waMyProfileAction
{
    public function execute()
    {
        parent::execute();
        $this->setThemeTemplate('my.profile.html');
        if (!waRequest::isXMLHttpRequest()) {
            $this->setLayout(new authFrontendLayout());
        }
    }
}
