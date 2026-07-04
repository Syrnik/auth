<?php

class authDefaultLayout extends waLayout
{
    public function execute()
    {
        $this->executeAction('sidebar', new authBackendSidebarAction());
    }
}
