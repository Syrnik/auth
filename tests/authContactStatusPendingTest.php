<?php
/**
 * @author Serge Rodovnichenko <serge@syrnik.com>
 * @copyright Serge Rodovnichenko, 2026
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * A stamp of the login value settles a pending profile confirmation of that
 * very value (AUTH-986). Without it, a phone proven by OTP login kept showing
 * "awaiting confirmation" in the profile until the row expired.
 *
 * What must survive: a pending login change (its value is never the current
 * login one), and a confirmation pending for some other value.
 */
class authContactStatusPendingTest extends TestCase
{
    use authTestTemporaryTablesTrait;
    use authTestContactFixtureTrait;

    private authProfileConfirmModel $model;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpTemporaryTables('auth_profile_confirm');
        $this->model = new authProfileConfirmModel();
    }

    protected function tearDown(): void
    {
        $this->tearDownTestContacts();
        $this->tearDownTemporaryTables();
        parent::tearDown();
    }

    public function testConfirmingTheLoginPhoneDropsItsPendingConfirmation(): void
    {
        $digits  = $this->randomPhone();
        $contact = $this->createTestContact(['phone' => $digits]);
        $this->model->issue((int)$contact->getId(), 'phone', $digits, true, 'login_phone');

        // Formatted differently from the row: both sides are normalized.
        $this->assertTrue(authContactStatus::confirmPhone((int)$contact->getId(), '+' . $digits));

        $this->assertNull($this->model->getPending((int)$contact->getId(), 'phone'));
    }

    public function testConfirmingTheLoginPhoneKeepsAPendingLoginChange(): void
    {
        $current = $this->randomPhone();
        $new     = $this->randomPhone();
        $contact = $this->createTestContact(['phone' => $current]);
        $this->model->issue((int)$contact->getId(), 'phone', $new, true, 'login_phone');

        $this->assertTrue(authContactStatus::confirmPhone((int)$contact->getId(), $current));

        $row = $this->model->getPending((int)$contact->getId(), 'phone');
        $this->assertNotNull($row);
        $this->assertSame($new, $row['value']);
    }

    public function testConfirmingASecondaryPhoneKeepsAPendingPromotionOfIt(): void
    {
        // A change of the login to a number the contact already has as a
        // secondary: proving that secondary some other way is not the change.
        $login     = $this->randomPhone();
        $secondary = $this->randomPhone();
        $contact   = $this->createTestContact(['phone' => [$login, $secondary]]);
        $this->assertSecondary($contact, $secondary);
        $this->model->issue((int)$contact->getId(), 'phone', $secondary, true, 'login_phone');

        $this->assertTrue(authContactStatus::confirmPhone((int)$contact->getId(), $secondary));

        $this->assertNotNull($this->model->getPending((int)$contact->getId(), 'phone'));
    }

    public function testConfirmPrimaryEmailDropsThePendingConfirmationRegardlessOfCase(): void
    {
        // Recovery and signup confirm stamp the email as stored; the profile
        // stores its pending rows lowercased.
        $email   = 'Auth986-' . uniqid() . '@Example.TEST';
        $contact = $this->createTestContact(['email' => $email]);
        $this->model->issue((int)$contact->getId(), 'email', mb_strtolower($email), false, 'login_email');

        $this->assertTrue(authContactStatus::confirmPrimary('email', (int)$contact->getId()));

        $this->assertNull($this->model->getPending((int)$contact->getId(), 'email'));
    }

    public function testAFailedStampLeavesThePendingRowAlone(): void
    {
        $digits  = $this->randomPhone();
        $contact = $this->createTestContact(['phone' => $digits]);
        $this->model->issue((int)$contact->getId(), 'phone', $digits, true, 'login_phone');

        $this->assertFalse(authContactStatus::confirmPhone((int)$contact->getId(), $this->randomPhone()));

        $this->assertNotNull($this->model->getPending((int)$contact->getId(), 'phone'));
    }

    // -------------------------------------------------------------------------

    private function randomPhone(): string
    {
        return '7900' . random_int(1000000, 9999999);
    }

    private function assertSecondary(waContact $contact, string $phone): void
    {
        $row = (new waContactDataModel())->getByField([
            'contact_id' => $contact->getId(),
            'field'      => 'phone',
            'value'      => $phone,
        ]);
        $this->assertNotNull($row, 'fixture sanity: the secondary phone must have been saved');
        $this->assertGreaterThan(0, (int)$row['sort'], 'fixture sanity: it must not be the login phone');
    }
}
