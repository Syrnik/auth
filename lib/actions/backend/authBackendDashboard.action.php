<?php

class authBackendDashboardAction extends authBackendDomainSettingsAction
{
    protected function getUrlSegment(): string
    {
        return '';
    }

    protected function getTemplateName(): string
    {
        return 'BackendDashboard';
    }
}
