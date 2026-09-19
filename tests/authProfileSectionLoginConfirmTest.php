<?php
/**
 * @author Serge Rodovnichenko <serge@syrnik.com>
 * @copyright Serge Rodovnichenko, 2026
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * authProfileSectionLogin::applyConfirmedValue() — the two cases it now has
 * to tell apart (AUTH-51, decision 1 of docs/adr/005-value-confirmation.md):
 * a genuinely new login value (write to index 0) versus a secondary value at
 * index > 0 that was already there and is only being reconfirmed (stamp in
 * place, never promoted to index 0). Both arrive through the same method
 * with no index parameter to distinguish them by — isAlreadyStored() is the
 * whole of that distinction, so this is what actually proves the promotion
 * bug this refactor exists to avoid does not resurface here.
 *
 * A real contact is required: applyConfirmedValue() reads the section's own
 * field config (waContactFields::get('email','enabled')) and runs isTaken()
 * as real SQL against wa_contact/wa_contact_emails, neither reachable
 * through a fixture. login_methods is overridden so authProfileSectionLoginEmail
 * is the available section for 'email' regardless of this install's actual
 * domain config.
 */
class authProfileSectionLoginConfirmTest extends TestCase
{
    use authTestContactFixtureTrait;
    use authTestConfigOverrideTrait;

    private const LOGIN_EMAIL = 'login@example.test';
    private const SECOND_EMAIL = 'second@example.test';

    protected function setUp(): void
    {
        parent::setUp();
        $this->overrideAuthConfig(['login_methods' => ['email']]);
    }

    protected function tearDown(): void
    {
        $this->restoreAuthConfig();
        $this->tearDownTestContacts();
        parent::tearDown();
    }

    public function testReconfirmingASecondaryValueStampsItInPlaceWithoutPromotingIt(): void
    {
        $contact = $this->createTwoEmailContact();

        $section = new authProfileSectionLoginEmail($contact);
        $applied = $section->applyConfirmedValue(self::SECOND_EMAIL);

        $this->assertTrue($applied, implode('; ', $this->flattenErrors($section->getErrors())));

        $emails = (new waContactEmailsModel())
            ->select('email, sort, status')
            ->where('contact_id = i:id', ['id' => $contact->getId()])
            ->order('sort')
            ->fetchAll();

        // Nothing moved: the login is still at sort 0, the second address is
        // still at sort 1 — only its status changed.
        $this->assertSame(self::LOGIN_EMAIL, $emails[0]['email']);
        $this->assertSame(self::SECOND_EMAIL, $emails[1]['email']);
        $this->assertSame(waContactEmailsModel::STATUS_CONFIRMED, $emails[1]['status']);
    }

    public function testAGenuinelyNewValueIsWrittenToIndexZero(): void
    {
        $contact = $this->createTwoEmailContact();

        $section = new authProfileSectionLoginEmail($contact);
        $applied = $section->applyConfirmedValue('brand-new@example.test');

        $this->assertTrue($applied, implode('; ', $this->flattenErrors($section->getErrors())));

        $login = (new waContactEmailsModel())
            ->select('email, status')
            ->where('contact_id = i:id AND sort = 0', ['id' => $contact->getId()])
            ->fetchAssoc();

        $this->assertSame('brand-new@example.test', $login['email']);
        $this->assertSame(waContactEmailsModel::STATUS_CONFIRMED, $login['status']);
    }

    // -------------------------------------------------------------------------

    private function createTwoEmailContact(): waContact
    {
        $contact = $this->createTestContact(['email' => self::LOGIN_EMAIL, 'password' => 'irrelevant']);

        (new waContactEmailsModel())->insert([
            'contact_id' => $contact->getId(),
            'email'      => self::SECOND_EMAIL,
            'sort'       => 1,
            'status'     => waContactEmailsModel::STATUS_UNCONFIRMED,
        ]);

        return $contact;
    }

    private function flattenErrors(array $errors): array
    {
        $flat = [];
        foreach ($errors as $messages) {
            foreach ((array)$messages as $message) {
                $flat[] = (string)$message;
            }
        }
        return $flat;
    }
}
