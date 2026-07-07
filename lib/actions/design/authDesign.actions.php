<?php
/**
 * Design section.
 */
class authDesignActions extends waDesignActions
{
    protected $design_url = '#/design/';
    protected $themes_url = '#/design/themes/';

    public function __construct()
    {
        if (!$this->getRights('design')) {
            throw new waRightsException(_ws("Access denied"));
        }
        $this->options['is_ajax'] = true;
    }

    public function defaultAction()
    {
        if (!waRequest::isXMLHttpRequest()) {
            $this->setLayout(new authDefaultLayout());
        }
        $this->getResponse()->setTitle(_ws('Design'));

        parent::defaultAction();
    }
}
