<?php
/**
 * @author Serge Rodovnichenko <serge@syrnik.com>
 * @copyright Serge Rodovnichenko, 2026
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * authProfileSectionEmail/Phone as authProfileSectionConfirmable — decision 1
 * of docs/adr/005-value-confirmation.md: getConfirmationUrl() answers null
 * unconditionally (nothing is ever withheld), and sendConfirmation()/
 * isConfirmed() are the separate, explicit "Confirm" flow for an
 * already-stored value.
 *
 * auth_profile_confirm and auth_throttle (sendConfirmation() -> startConfirmation()
 * checks otp_send) are shadowed — both tables this app owns. A real contact
 * is required for isConfirmed()/applyConfirmedValue(), which read/write
 * wa_contact_emails/wa_contact_data directly.
 */
class authProfileSectionConfirmTest extends TestCase
{
    use authTestContactFixtureTrait;
    use authTestConfigOverrideTrait;
    use authTestTemporaryTablesTrait;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpTemporaryTables(['auth_profile_confirm', 'auth_throttle']);
        // Email/phone are NOT login methods — the plain sections' own
        // condition for being available at all (authProfileSectionEmail::isAvailable()).
        $this->overrideAuthConfig(['login_methods' => []]);
        // authProfileFields keeps its own static per-domain cache (site's
        // personal_fields), never reset by authTestConfigOverrideTrait —
        // an earlier test class that ran in this same PHPUnit process could
        // have cached a personal_fields set that leaves 'email'/'phone' out,
        // and every isAvailable()/getEnabledFields() call below would then
        // silently see the wrong domain.
        authProfileFields::clearCache();
    }

    protected function tearDown(): void
    {
        $this->tearDownTemporaryTables();
        $this->restoreAuthConfig();
        authProfileFields::clearCache();
        $this->tearDownTestContacts();
        parent::tearDown();
    }

    public function testGetConfirmationUrlIsAlwaysNull(): void
    {
        $contact = $this->createTestContact(['email' => 'plain-' . uniqid() . '@example.test']);
        $section = new authProfileSectionEmail($contact);

        $this->assertNull($section->getConfirmationUrl(['email' => ['value' => 'anything@example.test']], 0));
        $this->assertNull($section->getConfirmationUrl([], null));
    }

    public function testIsConfirmedReflectsStoredStatus(): void
    {
        $email = 'plain-' . uniqid() . '@example.test';
        $contact = $this->createTestContact(['email' => $email]);
        $section = new authProfileSectionEmail($contact);

        $this->assertFalse($section->isConfirmed(0));

        authContactStatus::confirmEmail($contact->getId(), $email);
        // waContact::$cache is a static, process-wide cache keyed by contact
        // id — a *new* waContact object for the same id does not bypass it,
        // only removeCache() does. The stamp above wrote straight through
        // waContactEmailsModel, so without this the read above (isConfirmed()
        // => false) is what a second read would still see.
        $contact->removeCache('email');

        $this->assertTrue($section->isConfirmed(0));
    }

    public function testSendConfirmationIssuesARowTaggedWithThisSection(): void
    {
        $email = 'plain-' . uniqid() . '@example.test';
        $contact = $this->createTestContact(['email' => $email]);
        $section = new authProfileSectionEmail($contact);

        $url = $section->sendConfirmation(0);

        $this->assertNotNull($url, implode('; ', $this->flattenErrors($section->getErrors())));

        $model = new authProfileConfirmModel();
        $row   = $model->getPending($contact->getId(), 'email');
        $this->assertNotNull($row);
        $this->assertSame('email', $row['section']);
        $this->assertSame(mb_strtolower($email), $row['value']);
    }

    public function testSendConfirmationOnANonExistentIndexFails(): void
    {
        $contact = $this->createTestContact(['email' => 'plain-' . uniqid() . '@example.test']);
        $section = new authProfileSectionEmail($contact);

        $this->assertNull($section->sendConfirmation(5));
        $this->assertNotEmpty($section->getErrors());
    }

    public function testApplyConfirmedValueStampsWithoutWriting(): void
    {
        $email = 'plain-' . uniqid() . '@example.test';
        $contact = $this->createTestContact(['email' => $email]);
        $section = new authProfileSectionEmail($contact);

        $this->assertTrue($section->applyConfirmedValue($email));
        $this->assertTrue(authContactStatus::isPrimaryConfirmed('email', $contact->getId()));
    }

    public function testApplyConfirmedValueFailsForAValueNoLongerOnTheContact(): void
    {
        $contact = $this->createTestContact(['email' => 'plain-' . uniqid() . '@example.test']);
        $section = new authProfileSectionEmail($contact);

        $this->assertFalse($section->applyConfirmedValue('never-was-here@example.test'));
        $this->assertNotEmpty($section->getErrors());
    }

    // -------------------------------------------------------------------------

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
