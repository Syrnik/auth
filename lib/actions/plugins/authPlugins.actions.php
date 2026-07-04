<?php

class authPluginsActions extends waPluginsActions
{
    protected $plugins_hash = '#';
    protected $is_ajax = false;
    protected $shadowed = true;

    public function defaultAction()
    {
        if (!$this->getUser()->isAdmin($this->getApp())) {
            throw new waRightsException(_w('Access denied'));
        }

        if (!waRequest::isXMLHttpRequest()) {
            $this->setLayout(new authDefaultLayout());
        }

        parent::defaultAction();
    }
}
