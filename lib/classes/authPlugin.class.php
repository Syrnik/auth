<?php

abstract class authPlugin extends waPlugin
{
    /**
     * Absolute path to a template in the plugin's templates/ directory.
     * Returns null if the template does not exist.
     */
    public function getTemplatePath(string $part): ?string
    {
        $path = $this->path . '/templates/' . $part . '.html';
        return file_exists($path) ? $path : null;
    }
}
