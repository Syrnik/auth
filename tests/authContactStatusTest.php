<?php
/**
 * @author Serge Rodovnichenko <serge@syrnik.com>
 * @copyright Serge Rodovnichenko, 2026
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * authContactStatus — the one place that stamps wa_contact_emails.status /
 * wa_contact_data.status confirmed, and the one place that knows the two
 * models disagree on what "unknown" looks like.
 */
class authContactStatusTest extends TestCase
{
    use authTestContactFixtureTrait;

    protected function tearDown(): void
    {
        $this->tearDownTestContacts();
        parent::tearDown();
    }

    public function testConfirmEmailStampsStatus(): void
    {
        $email = 'confirm-email-' . uniqid() . '@example.test';
        $contact = $this->createTestContact(['email' => $email]);

        $this->assertTrue(authContactStatus::confirmEmail($contact->getId(), $email));

        $row = (new waContactEmailsModel())->getByField(['contact_id' => $contact->getId(), 'email' => $email]);
        $this->assertSame(waContactEmailsModel::STATUS_CONFIRMED, $row['status']);
    }

    public function testConfirmEmailReturnsFalseForAValueNotOnTheContact(): void
    {
        $contact = $this->createTestContact(['email' => 'confirm-email-' . uniqid() . '@example.test']);

        $this->assertFalse(authContactStatus::confirmEmail($contact->getId(), 'not-this-address@example.test'));
    }

    public function testConfirmPhoneNormalizesBeforeStamping(): void
    {
        // The stored form is whatever authProfileValues::preparePhones() produces
        // (cleanPhoneNumber() over the domain's transformPhone()), and
        // updateContactPhoneStatus() only runs cleanPhoneNumber() on what it is
        // given — a caller that skipped the transform step would stamp nothing.
        $phone = '+7900' . random_int(1000000, 9999999);
        $contact = $this->createTestContact(['phone' => $phone]);

        $stored = (new waContactDataModel())
            ->getByField(['contact_id' => $contact->getId(), 'field' => 'phone']);
        $this->assertNotNull($stored, 'fixture sanity: the phone must have been saved');

        $this->assertTrue(authContactStatus::confirmPhone($contact->getId(), $stored['value']));

        $row = (new waContactDataModel())
            ->getByField(['contact_id' => $contact->getId(), 'field' => 'phone']);
        $this->assertSame(waContactDataModel::STATUS_CONFIRMED, $row['status']);
    }

    public function testConfirmPhoneReturnsFalseForAValueNotOnTheContact(): void
    {
        $contact = $this->createTestContact(['phone' => '+7900' . random_int(1000000, 9999999)]);

        $this->assertFalse(authContactStatus::confirmPhone($contact->getId(), '+79990000000'));
    }

    public function testConfirmEmailIsANoOpWhenAlreadyConfirmed(): void
    {
        $email = 'confirm-email-' . uniqid() . '@example.test';
        $contact = $this->createTestContact(['email' => $email]);

        $this->assertTrue(authContactStatus::confirmEmail($contact->getId(), $email));
        // Second call: the model's own updateContactEmailStatus() skips the write
        // when the status already matches — still reports success, not a no-op-as-false.
        $this->assertTrue(authContactStatus::confirmEmail($contact->getId(), $email));
    }

    public function testConfirmPrimaryStampsTheSortZeroValue(): void
    {
        $email = 'confirm-primary-' . uniqid() . '@example.test';
        $contact = $this->createTestContact(['email' => $email]);

        $this->assertTrue(authContactStatus::confirmPrimary('email', $contact->getId()));
        $this->assertTrue(authContactStatus::isPrimaryConfirmed('email', $contact->getId()));
    }

    public function testIsPrimaryConfirmedFalseWhenNothingStamped(): void
    {
        $contact = $this->createTestContact(['email' => 'confirm-primary-' . uniqid() . '@example.test']);

        $this->assertFalse(authContactStatus::isPrimaryConfirmed('email', $contact->getId()));
    }

    public function testIsPrimaryConfirmedFalseWhenNoValueAtAll(): void
    {
        $contact = $this->createTestContact([]);

        $this->assertFalse(authContactStatus::isPrimaryConfirmed('email', $contact->getId()));
        $this->assertFalse(authContactStatus::isPrimaryConfirmed('phone', $contact->getId()));
    }

    public function testConfirmPrimaryReturnsFalseForAnUnknownField(): void
    {
        $contact = $this->createTestContact(['email' => 'confirm-primary-' . uniqid() . '@example.test']);

        $this->assertFalse(authContactStatus::confirmPrimary('login', $contact->getId()));
        $this->assertFalse(authContactStatus::isPrimaryConfirmed('login', $contact->getId()));
    }
}
