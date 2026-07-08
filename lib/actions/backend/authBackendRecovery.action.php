<?php

class authBackendRecoveryAction extends authBackendDomainSettingsAction
{
    protected function getUrlSegment(): string
    {
        return 'recovery';
    }

    protected function getTemplateName(): string
    {
        return 'BackendRecovery';
    }

    protected function collectSectionData(string $domain, array $current): array
    {
        $post = waRequest::post();

        return [
            'recovery_enabled' => !empty($post['recovery_enabled']),
            'rememberme'       => !empty($post['rememberme']),
        ];
    }
}
