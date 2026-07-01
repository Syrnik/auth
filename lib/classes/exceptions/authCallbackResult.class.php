<?php

class authCallbackResult
{
    public int $contact_id;
    public bool $is_new;

    public function __construct(int $contact_id, bool $is_new)
    {
        $this->contact_id = $contact_id;
        $this->is_new     = $is_new;
    }
}
