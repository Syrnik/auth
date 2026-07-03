<?php

/**
 * Shared JSON terminator for the auth frontend controllers/actions: send a JSON
 * body and end the request. Login and registration both answer XHR clients this
 * way; keeping it in one place means they can't drift apart (headers, flags).
 */
trait authJsonResponseTrait
{
    protected function sendJson(array $data): void
    {
        wa()->getResponse()->addHeader('Content-Type', 'application/json');
        wa()->getResponse()->sendHeaders();
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}
