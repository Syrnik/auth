<?php

class authFrontendLogoutAction extends waViewAction
{
    public function execute(): void
    {
        wa()->getAuth()->clearAuth();
        $redirect = authConfig::get('redirect_after_logout', '/');
        wa()->getResponse()->redirect($redirect);
    }
}
