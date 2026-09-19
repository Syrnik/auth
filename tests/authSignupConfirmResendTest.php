<?php
/**
 * @author Serge Rodovnichenko <serge@syrnik.com>
 * @copyright Serge Rodovnichenko, 2026
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * confirm/ (no token) — resend of the signup confirmation link, decision 3
 * of docs/adr/005-value-confirmation.md. Two things checked here, both
 * unreachable through authSignupConfirmModelTest alone:
 *
 *   - createToken() now replaces an earlier live token for the same contact
 *     (authSignupConfirmModel::deleteByContact()), so a resend cannot leave
 *     two links usable at once;
 *   - authFrontendConfirmAction::findUnconfirmedContact() — the
 *     anti-enumeration resolver — answers null identically for an unknown
 *     address and for an address whose account is already confirmed, and
 *     resolves the contact only for a genuinely pending one. Reached via
 *     Reflection, same as authFrontendMyConfirmAction::resolveSection() in
 *     authProfileConfirmHandoffTest — it touches no waRequest/waResponse.
 */
class authSignupConfirmResendTest extends TestCase
{
    use authTestContactFixtureTrait;
    use authTestTemporaryTablesTrait;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpTemporaryTables('auth_signup_confirm');
    }

    protected function tearDown(): void
    {
        $this->tearDownTemporaryTables();
        $this->tearDownTestContacts();
        parent::tearDown();
    }

    public function testCreateTokenReplacesAnEarlierLiveTokenForTheSameContact(): void
    {
        $model = new authSignupConfirmModel();

        $first  = $model->createToken(1, 'old-address@example.test');
        $second = $model->createToken(1, 'new-address@example.test');

        $this->assertNull($model->getValid($first));
        $row = $model->getValid($second);
        $this->assertNotNull($row);
        $this->assertSame('new-address@example.test', $row['email']);
    }

    public function testCreateTokenDoesNotReplaceAnotherContactsToken(): void
    {
        $model = new authSignupConfirmModel();

        $other = $model->createToken(2, 'other@example.test');
        $model->createToken(1, 'mine@example.test');

        $this->assertNotNull($model->getValid($other));
    }

    public function testDeleteByContactRemovesAllOfThatContactsTokens(): void
    {
        $model = new authSignupConfirmModel();
        $token = $model->createToken(1, 'a@example.test');

        $model->deleteByContact(1);

        $this->assertNull($model->getValid($token));
    }

    public function testFindUnconfirmedContactIsNullForAnUnknownAddress(): void
    {
        $this->assertNull($this->findUnconfirmedContact('nobody-' . uniqid() . '@example.test'));
    }

    public function testFindUnconfirmedContactIsNullForAMalformedAddress(): void
    {
        $this->assertNull($this->findUnconfirmedContact('not-an-email'));
        $this->assertNull($this->findUnconfirmedContact(''));
    }

    public function testFindUnconfirmedContactIsNullOnceTheAddressIsConfirmed(): void
    {
        $email = 'resend-' . uniqid() . '@example.test';
        $contact = $this->createTestContact(['email' => $email]);
        authContactStatus::confirmEmail($contact->getId(), $email);

        $this->assertNull($this->findUnconfirmedContact($email));
    }

    public function testFindUnconfirmedContactResolvesAGenuinelyPendingOne(): void
    {
        $email = 'resend-' . uniqid() . '@example.test';
        $contact = $this->createTestContact(['email' => $email]);

        $found = $this->findUnconfirmedContact($email);

        $this->assertNotNull($found);
        $this->assertSame($contact->getId(), $found->getId());
    }

    // -------------------------------------------------------------------------

    private function findUnconfirmedContact(string $email): ?waContact
    {
        $action = new authFrontendConfirmAction();
        $method = new ReflectionMethod(authFrontendConfirmAction::class, 'findUnconfirmedContact');
        $method->setAccessible(true);

        return $method->invoke($action, $email);
    }
}
