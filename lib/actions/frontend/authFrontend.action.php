<?php

/**
 * App root ('/auth/'). Also the URL the backend Design section uses for the
 * theme preview link, so this must resolve to something other than a 404.
 */
class authFrontendAction extends waViewAction
{
    public function execute(): void
    {
        $url = authHelper::isLoggedIn() ? authHelper::getMyUrl() : authHelper::getLoginUrl();
        wa()->getResponse()->redirect($url);
    }
}
