<?php

/**
 * Bare app entry point (no domain in the URL): picks a domain (last visited,
 * via cookie, or the first one) and redirects into the settings/<domain>/
 * URL scheme every other backend screen expects.
 */
class authBackendAction extends waViewAction
{
    public function execute(): void
    {
        $domains = array_keys(wa()->getRouting()->getByApp('auth'));

        if (!$domains) {
            $this->setLayout(new authDefaultLayout());
            $this->setTemplate('BackendDashboard');
            $this->view->assign([
                'domain'     => '',
                'no_domains' => true,
            ]);
            return;
        }

        $domain = waRequest::cookie(authBackendDomainSettingsAction::DOMAIN_COOKIE, '', 'string');
        if (!$domain || !in_array($domain, $domains, true)) {
            $domain = $domains[0];
        }

        wa()->getResponse()->redirect(wa()->getAppUrl('auth', true) . 'settings/' . urlencode($domain) . '/');
    }
}
