<?php

/**
 * Per-domain settings of the user profile page (my/).
 *
 * A screen of its own rather than a line on the Authorization one, because what
 * it governs is not a way in: the profile is what a signed-in visitor may do
 * with their own account, and account deletion is the first of those decisions
 * an admin has to make deliberately.
 */
class authBackendProfileAction extends authBackendDomainSettingsAction
{
    protected function getUrlSegment(): string
    {
        return 'profile';
    }

    protected function getTemplateName(): string
    {
        return 'BackendProfile';
    }

    protected function collectSectionData(string $domain, array $current): array
    {
        $post = waRequest::post();

        return [
            'delete_account_enabled' => !empty($post['delete_account_enabled']),
        ];
    }
}
