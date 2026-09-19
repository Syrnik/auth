<?php
/**
 * @author Serge Rodovnichenko <serge@syrnik.com>
 * @copyright Serge Rodovnichenko, 2026
 */

declare(strict_types=1);

/**
 * A throwaway waContact for tests that stamp wa_contact_emails.status /
 * wa_contact_data.status.
 *
 * authTestTemporaryTablesTrait only shadows tables this app owns
 * (auth_*) — wa_contact and its satellite tables are the framework's own and
 * waContact::save() writes across a dozen of them, so there is no single
 * table to clone with CREATE TEMPORARY TABLE the way that trait does. A real
 * contact is created instead, and waContact::delete() is trusted to clean up
 * every table it touched on the way in, the same way it would for a contact
 * created by hand in the backend.
 */
trait authTestContactFixtureTrait
{
    /** @var int[] */
    private array $test_contact_ids = [];

    /**
     * @param array $data field_id => value, e.g. ['email' => '...', 'phone' => '...']
     */
    protected function createTestContact(array $data = []): waContact
    {
        $contact = new waContact();
        foreach ($data as $field_id => $value) {
            $contact[$field_id] = $value;
        }
        if (!isset($data['is_user'])) {
            $contact['is_user'] = 1;
        }
        $contact->save();

        $this->test_contact_ids[] = $contact->getId();

        return $contact;
    }

    protected function tearDownTestContacts(): void
    {
        foreach ($this->test_contact_ids as $id) {
            $contact = new waContact($id);
            if ($contact->exists()) {
                $contact->delete();
            }
        }
        $this->test_contact_ids = [];
    }
}
