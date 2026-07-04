<?php

class authBackendSidebarAction extends waViewAction
{
    public function execute(): void
    {
        $domains = array_keys(wa()->getRouting()->getByApp('auth'));

        // Whether auth is actually switched on for each site (>=1 login method).
        $enabled = [];
        foreach ($domains as $d) {
            $enabled[$d] = authConfig::isEnabled($d);
        }

        // The settings screen falls back to the first site when none is
        // requested, so mirror that here to keep the right item highlighted.
        $current = waRequest::get('domain', '', 'string');
        if (!$current && $domains) {
            $current = $domains[0];
        }

        // The 'plugins/' route dispatches straight to module 'plugins' with
        // an empty action, so highlighting must key off module, not action.
        $module = waRequest::param('module', 'settings', 'string');

        $this->view->assign([
            'domains'         => $domains,
            'domains_enabled' => $enabled,
            'current_domain'  => $current,
            'action'          => $module === 'plugins' ? 'plugins' : waRequest::param('action', 'settings', 'string'),
        ]);
    }
}
