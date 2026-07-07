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
    }

    public function defaultAction()
    {
        $this->setLayout(new authDefaultLayout());
        $this->getResponse()->setTitle(_ws('Design'));

        parent::defaultAction();
    }
}
