<?php
/**
 * @author Serge Rodovnichenko <serge@syrnik.com>
 * @copyright Serge Rodovnichenko, 2026
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * authFrontendMyConfirmAction::resolveSection() — the fix for the handoff bug
 * found while designing AUTH-51 (docs/adr/005-value-confirmation.md,
 * decision 2): a row must redeem through the exact section that issued it,
 * never through whatever else resolves for the same field once
 * login_methods has changed since.
 *
 * Reached through Reflection on the private method, the same way
 * authBackendCaptchaActionTest reaches authBackendCaptchaAction's private
 * helpers — resolveSection() reads authConfig/the registry but never touches
 * waRequest/waResponse, so this is the cheapest way to exercise it without
 * risking apply()'s own redirect()/exit() at the end of the real flow.
 */
class authProfileConfirmHandoffTest extends TestCase
{
    use authTestConfigOverrideTrait;
    use authTestTemporaryTablesTrait;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpTemporaryTables('auth_profile_confirm');
    }

    protected function tearDown(): void
    {
        $this->tearDownTemporaryTables();
        $this->restoreAuthConfig();
        parent::tearDown();
    }

    public function testRowIssuedByThePlainSectionDoesNotRedeemThroughTheLoginSection(): void
    {
        // Issued while email is NOT a login method — the plain section's own
        // situation when it calls startConfirmation().
        $this->overrideAuthConfig(['login_methods' => []]);
        $row = $this->rowWithSection('email');

        // The domain now signs people in with email — login_methods changed
        // while this 1h-TTL token was still live.
        $this->overrideAuthConfig(['login_methods' => ['email']]);

        $section = $this->resolveSection($row);

        // Must NOT be authProfileSectionLoginEmail — that would write
        // $row['value'] to sort = 0 (the login) via applyConfirmedValue(),
        // promoting a secondary address that was never checked against
        // isTaken() when the row was issued.
        $this->assertNotInstanceOf(authProfileSectionLoginEmail::class, $section);
        // The plain section is no longer available either (email is now a
        // login field, authProfileSectionEmail::isAvailable() says no) — the
        // only correct answer left is "cannot be applied", not a substitute.
        $this->assertNull($section);
    }

    public function testRowIssuedByTheLoginSectionDoesNotRedeemThroughThePlainSection(): void
    {
        $this->overrideAuthConfig(['login_methods' => ['email']]);
        $row = $this->rowWithSection('login_email');

        $this->overrideAuthConfig(['login_methods' => []]);

        $section = $this->resolveSection($row);

        $this->assertNotInstanceOf(authProfileSectionEmail::class, $section);
        $this->assertNull($section);
    }

    public function testRowRedeemsThroughTheIssuingSectionWhenConfigDidNotChange(): void
    {
        $this->overrideAuthConfig(['login_methods' => []]);
        $row = $this->rowWithSection('email');

        $section = $this->resolveSection($row);

        $this->assertInstanceOf(authProfileSectionEmail::class, $section);
    }

    public function testLegacyRowWithNoSectionFallsBackToFieldResolution(): void
    {
        // A row issued before the 'section' column existed: the only
        // information available is the field, same as before this fix.
        $this->overrideAuthConfig(['login_methods' => []]);
        $row = $this->rowWithSection('');

        $section = $this->resolveSection($row);

        $this->assertInstanceOf(authProfileSectionEmail::class, $section);
    }

    // -------------------------------------------------------------------------

    private function rowWithSection(string $section): array
    {
        $model = new authProfileConfirmModel();
        $result = $model->issue(1, 'email', 'handoff-' . uniqid() . '@example.test', false, $section);

        return $model->getValid($result['token']);
    }

    private function resolveSection(array $row): ?authProfileSectionConfirmable
    {
        $action = new authFrontendMyConfirmAction();
        $method = new ReflectionMethod(authFrontendMyConfirmAction::class, 'resolveSection');
        $method->setAccessible(true);

        return $method->invoke($action, $row);
    }
}
