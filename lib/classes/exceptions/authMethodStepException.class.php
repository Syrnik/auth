<?php

class authMethodStepException extends waException
{
    protected array $template_vars;

    public function __construct(array $template_vars = [])
    {
        $this->template_vars = $template_vars;
        parent::__construct('Auth method needs another step');
    }

    public function getTemplateVars(): array
    {
        return $this->template_vars;
    }
}
