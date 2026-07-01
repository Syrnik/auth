<?php

class authBackendAction extends authBackendSettingsAction
{
    public function execute(): void
    {
        $this->setLayout(new authDefaultLayout());
        $this->setTemplate('BackendSettings');
        parent::execute();
    }
}
