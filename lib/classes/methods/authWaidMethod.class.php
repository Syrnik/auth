<?php

class authWaidMethod extends authBuiltinMethod implements authMethod
{
    const AUTH_TYPE    = 'oauth';
    const HAS_RECOVERY = false;

    public function getId(): string
    {
        return 'waid';
    }

    public function getName(): string
    {
        return 'Webasyst ID';
    }

    /**
     * Called when the login form is submitted with method=waid (POST fallback).
     * Normally the user clicks the WAID button which links to getCallbackUrl()
     * directly, bypassing authenticate() entirely.
     */
    public function authenticate(array $params): ?int
    {
        $adapter = $this->getAdapter();
        if (!$adapter) {
            throw new waException('Webasyst ID is not configured.');
        }
        $url = $adapter->getHealthyRedirectUri();
        if (!$url) {
            throw new waException('Webasyst ID auth endpoint is not available.');
        }
        wa()->getResponse()->redirect($url);
        return null;
    }

    /**
     * Handles auth/callback/waid/ route.
     *
     * First visit (no ?code): initiates WAID OAuth redirect.
     * Return visit (with ?code from WAID): exchanges code, resolves contact,
     * returns authCallbackResult for the auth pipeline (guards, challenges, login).
     */
    public function handleCallback(array $params): authCallbackResult
    {
        $adapter = $this->getAdapter();
        if (!$adapter) {
            throw new waException('Webasyst ID is not configured.');
        }

        if (empty($params['code'])) {
            // First leg: redirect user to WAID auth center.
            $url = $adapter->getHealthyRedirectUri();
            if (!$url) {
                throw new waException('Webasyst ID auth endpoint is not available.');
            }
            wa()->getResponse()->redirect($url);
            exit;
        }

        // Second leg: WAID returned with auth code — exchange and identify contact.
        $data = $adapter->processCallback();

        [$contact_id, $is_new] = $this->findOrCreateContact($data);
        return new authCallbackResult($contact_id, $is_new);
    }

    public function getCallbackUrl(): string
    {
        return authHelper::getCallbackUrl('waid');
    }

    // -------------------------------------------------------------------------

    private function getAdapter(): ?authWaidAdapter
    {
        $auth_config = wa()->getAuthConfig();
        $credentials = $auth_config['adapters']['webasystID'] ?? [];
        if (empty($credentials['app_id'])) {
            return null;
        }
        return new authWaidAdapter($credentials);
    }

    /**
     * Find existing contact by WAID source_id or email, or create a new one.
     * Returns [contact_id, is_new].
     */
    private function findOrCreateContact(array $data): array
    {
        $field              = 'webasystID_id';
        $contact_data_model = new waContactDataModel();

        // Find by WAID contact ID (fastest path for returning users).
        $row = $contact_data_model->getByField([
            'field' => $field,
            'value' => $data['source_id'],
            'sort'  => 0,
        ]);
        if ($row) {
            return [(int) $row['contact_id'], false];
        }

        // Find by primary email and link WAID ID to existing account.
        $email = $this->extractEmail($data);
        if ($email) {
            $contact_model = new waContactModel();
            $contact_id    = (int) $contact_model->query(
                "SELECT c.id FROM wa_contact_emails e
                 JOIN wa_contact c ON e.contact_id = c.id
                 WHERE e.email = s:email AND e.sort = 0 AND c.password != ''",
                ['email' => $email]
            )->fetchField('id');

            if ($contact_id) {
                $contact_data_model->insert([
                    'contact_id' => $contact_id,
                    'field'      => $field,
                    'value'      => $data['source_id'],
                    'sort'       => 0,
                ]);
                return [$contact_id, false];
            }
        }

        // Create new contact.
        $contact    = new waContact();
        $save_data  = $data;
        $save_data[$field]             = $data['source_id'];
        $save_data['create_method']    = 'webasystID';
        $save_data['create_app_id']    = 'auth';
        unset($save_data['source'], $save_data['source_id'], $save_data['photo_url']);
        // Unusable password so the account cannot be brute-forced via password form.
        $contact->setPassword(
            substr(waContact::getPasswordHash(uniqid((string) time(), true)), 0, -1),
            true
        );
        $contact->save($save_data);

        return [(int) $contact->getId(), true];
    }

    private function extractEmail(array $data): string
    {
        if (empty($data['email'])) {
            return '';
        }
        $email = $data['email'];
        if (is_array($email)) {
            $email = $email[0]['value'] ?? ($email[0] ?? '');
        }
        return is_string($email) ? trim($email) : '';
    }
}
