<?php

class authFrontendLogoutAction extends waViewAction
{
    public function execute(): void
    {
        wa()->getAuth()->clearAuth();
        // `?:` not a get() default: several redirect_* keys are declared null in
        // config.php, so the stored value IS null and the default never applies
        // (see authConfig::get docblock). A null here would emit an empty Location.
        $redirect = authConfig::get('redirect_after_logout') ?: '/';
        wa()->getResponse()->redirect($redirect);
    }
}
