<?php

/**
 * Shared contact resolution for any OAuth-style method (Webasyst ID, VK, Google, ...).
 * Expects the normalized shape all waAuthAdapter::auth() implementations return:
 * ['source' => ..., 'source_id' => ..., 'email' => ..., 'photo_url' => ..., ...].
 */
class authContactResolver
{
    /**
     * Find an existing contact for this identity, or run signup guards and
     * create a new one. Guards run against the raw OAuth data BEFORE any
     * contact is created, same as the plain registration form — a blocked
     * signup never touches the database, so there is nothing to roll back.
     * Respects the signup_enabled setting: an unknown OAuth identity is not
     * silently turned into a new account just because registration is off.
     * Returns [contact_id, is_new]. Throws authGuardException if blocked.
     */
    public static function resolve(array $data): array
    {
        $contact_id = self::find($data);
        if ($contact_id !== null) {
            return [$contact_id, false];
        }

        if (!authConfig::get('signup_enabled')) {
            throw new authGuardException('Регистрация отключена.');
        }

        foreach (authPluginManager::getGuardsEnabled('signup') as $guard) {
            $guard->checkSignup($data);
        }

        return [self::create($data), true];
    }

    /**
     * Look up an existing contact by source id or linked email. Returns null
     * if no match — the caller should treat this as a new signup.
     */
    public static function find(array $data): ?int
    {
        $field              = $data['source'] . '_id';
        $contact_data_model = new waContactDataModel();

        $row = $contact_data_model->getByField([
            'field' => $field,
            'value' => $data['source_id'],
            'sort'  => 0,
        ]);
        if ($row) {
            return (int) $row['contact_id'];
        }

        $email = self::extractEmail($data);
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
                return $contact_id;
            }
        }

        return null;
    }

    /**
     * Create a new contact from OAuth data. Caller is responsible for
     * running signup guards first — this method does not check them.
     */
    public static function create(array $data): int
    {
        $field      = $data['source'] . '_id';
        $contact    = new waContact();
        $save_data  = $data;
        $save_data[$field]             = $data['source_id'];
        $save_data['create_method']    = $data['source'];
        $save_data['create_app_id']    = 'auth';
        unset($save_data['source'], $save_data['source_id'], $save_data['photo_url']);
        // Unusable password so the account cannot be brute-forced via password form.
        $contact->setPassword(
            substr(waContact::getPasswordHash(uniqid((string) time(), true)), 0, -1),
            true
        );
        $contact->save($save_data);

        return (int) $contact->getId();
    }

    private static function extractEmail(array $data): string
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
