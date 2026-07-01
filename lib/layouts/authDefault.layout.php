<?php

class authDefaultLayout extends waLayout
{
    public function execute()
    {
        $this->executeAction('sidebar', new authBackendSidebarAction());
        $this->assign('module', waRequest::get('module', 'backend'));
        $this->assign('action', waRequest::get('action', 'settings'));
    }
}
