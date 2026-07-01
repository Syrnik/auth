<?php

class authBackendSidebarAction extends waViewAction
{
    public function execute(): void
    {
        $domains = array_keys(wa()->getRouting()->getByApp('auth'));

        $this->view->assign([
            'domains'        => $domains,
            'current_domain' => waRequest::get('domain', '', 'string'),
            'action'         => waRequest::get('action', 'settings', 'string'),
        ]);
    }
}
