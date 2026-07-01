<?php

class authGuardException extends waException
{
    public function __construct(string $message)
    {
        parent::__construct($message, 403);
    }
}
