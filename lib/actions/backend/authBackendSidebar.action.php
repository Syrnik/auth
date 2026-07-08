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

        // Every per-domain page supplies 'domain' via routing.backend.php;
        // fall back to the first domain only for pages outside that scheme.
        $current_domain = waRequest::param('domain', '', 'string');
        if (!$current_domain && $domains) {
            $current_domain = $domains[0];
        }

        $this->view->assign([
            'domains'         => $domains,
            'domains_enabled' => $enabled,
            'current_domain'  => $current_domain,
            'section'         => waRequest::param('action', '', 'string'),
            'module'          => waRequest::param('module', 'backend', 'string'),
        ]);
    }
}
