<?php

/**
 * Shared contact resolution for any OAuth-style method (Webasyst ID, VK, Google, ...).
 * Expects the normalized shape all waAuthAdapter::auth() implementations return:
 * ['source' => ..., 'source_id' => ..., 'email' => ..., 'photo_url' => ..., ...].
 */
class authContactResolver
{
    /**
     * Find existing contact by source id or email, or create a new one.
     * Returns [contact_id, is_new].
     */
    public static function findOrCreate(array $data): array
    {
        $field              = $data['source'] . '_id';
        $contact_data_model = new waContactDataModel();

        $row = $contact_data_model->getByField([
            'field' => $field,
            'value' => $data['source_id'],
            'sort'  => 0,
        ]);
        if ($row) {
            return [(int) $row['contact_id'], false];
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
                return [$contact_id, false];
            }
        }

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

        return [(int) $contact->getId(), true];
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
