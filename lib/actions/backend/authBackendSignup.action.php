<?php

class authBackendSignupAction extends authBackendDomainSettingsAction
{
    protected function getUrlSegment(): string
    {
        return 'signup';
    }

    protected function getTemplateName(): string
    {
        return 'BackendSignup';
    }

    protected function collectSectionData(string $domain, array $current): array
    {
        $post = waRequest::post();

        return [
            'signup_enabled' => !empty($post['signup_enabled']),
            'signup_confirm' => !empty($post['signup_confirm']),
        ];
    }
}
