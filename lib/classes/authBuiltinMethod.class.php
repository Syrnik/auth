<?php

abstract class authBuiltinMethod
{
    abstract public function getId(): string;

    /**
     * Absolute path to a template in themes/default/ directory.
     * Returns null if the template does not exist.
     */
    public function getTemplatePath(string $part): ?string
    {
        $path = wa()->getAppPath('themes/default/' . $this->getId() . '.' . $part . '.html', 'auth');
        return file_exists($path) ? $path : null;
    }
}
