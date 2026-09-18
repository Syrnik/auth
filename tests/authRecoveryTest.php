<?php
/**
 * @author Serge Rodovnichenko <serge@syrnik.com>
 * @copyright Serge Rodovnichenko, 2026
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * authRecovery — the coordinator, exercised against the response it actually
 * hands the action (authRecoveryResponse), not any lower-level internal
 * state. Comparing at a lower level could stay green while the rendered page
 * still diverges between "identifier resolved" and "identifier did not" —
 * exactly the oracle docs/adr/004-recovery-channels.md decision 5 exists to
 * close, and specifically the risk with a code-based provider (phone): its
 * success state IS a code-entry step, so "pending row exists -> code form,
 * else -> sent page" would leak existence through the response shape alone.
 *
 * auth_password_recovery and auth_throttle are shadowed with
 * authTestTemporaryTablesTrait (both are tables this app owns — request()
 * always goes through authThrottle too); recovery_channels/login_methods
 * through authTestConfigOverrideTrait. A minimal real contact is created and
 * torn down for the two built-in providers' resolution path — their claims()/
 * SQL is unreachable through a fixture and needs the actual contact tables.
 */
class authRecoveryTest extends TestCase
{
    use authTestTemporaryTablesTrait;
    use authTestConfigOverrideTrait;

    private const TEST_EMAIL = 'auth-recovery-test@example.test';
    // '+'-prefixed so authProfileValues::transformPhone() takes its "already
    // international" early return and never consults domain phone-transform
    // settings — the normalized form is then just cleanPhoneNumber()'s own
    // stripping, independent of whatever this install's domain config says.
    private const TEST_PHONE_RAW        = '+7 999 000-11-22';
    private const TEST_PHONE_NORMALIZED = '79990001122';

    private int $contact_id = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpTemporaryTables(['auth_password_recovery', 'auth_throttle']);
        // Belt-and-suspenders against exactly the orphan this suite hit once
        // already: a run that crashes between createTestContact() and
        // tearDown() (before that method's own contact-delete-first ordering
        // existed) leaves a same-named contact behind, and findContactId()'s
        // "ORDER BY id LIMIT 1" would then resolve TEST_EMAIL/TEST_PHONE to
        // that stale row instead of this run's fresh one. Sweeping by name
        // before creating a new one closes the window tearDown() alone can't
        // (a crash tearDown() itself can't run past).
        (new waContactModel())->deleteByField('name', 'Auth Recovery Test');
        $this->contact_id = $this->createTestContact(self::TEST_EMAIL, self::TEST_PHONE_NORMALIZED);
    }

    protected function tearDown(): void
    {
        // The real contact is the one cleanup step that must survive a
        // failure in any other one — wa_contact/wa_contact_emails/
        // wa_contact_data are not this app's own tables (no temp-table
        // shadowing for them), so a contact this suite creates and fails to
        // delete lingers in the dev DB and can shadow a later run's own
        // fresh contact: findContactId()'s "ORDER BY id LIMIT 1" would then
        // resolve the constant test email/phone to whichever row has the
        // lower id — an old orphan, not the one this run just made — and
        // every contact_id assertion downstream starts failing for a reason
        // that has nothing to do with the code under test.
        $this->deleteTestContact($this->contact_id);
        authRecovery::clearHandle();
        $this->restoreAuthConfig();
        $this->tearDownTemporaryTables();
        parent::tearDown();
    }

    // -------------------------------------------------------------------------
    // Format rejection: no provider claims it. Not an existence answer — it
    // depends only on recovery_channels, never on any account.
    // -------------------------------------------------------------------------

    public function testUnrecognizedIdentifierIsRejectedWithoutMatchingAnyProvider(): void
    {
        $this->overrideAuthConfig(['recovery_channels' => ['testrecovery_plugin']]);

        $response = authRecovery::request('this matches nothing at all');

        $this->assertNotSame('', $response->error);
        $this->assertFalse($response->needsCode);
    }

    public function testAProviderNotListedInRecoveryChannelsNeverClaimsAnything(): void
    {
        // 'test:foo' is exactly the shape testrecovery_plugin claims — but it
        // is not enabled here, so it must not be asked at all.
        $this->overrideAuthConfig(['recovery_channels' => ['email']]);

        $response = authRecovery::request('test:foo');

        $this->assertNotSame('', $response->error);
    }

    // -------------------------------------------------------------------------
    // Email provider (link-based, needsCode() === false): headline
    // anti-enumeration property.
    // -------------------------------------------------------------------------

    public function testEmailResponseIsIdenticalWhetherOrNotTheAddressResolves(): void
    {
        $this->overrideAuthConfig(['recovery_channels' => ['email']]);

        $found    = authRecovery::request(self::TEST_EMAIL);
        $notFound = authRecovery::request('nobody-with-this-address@example.test');

        $this->assertSame($found->needsCode, $notFound->needsCode);
        $this->assertSame($found->error, $notFound->error);
        $this->assertFalse($found->needsCode);
        $this->assertSame('', $found->error);
    }

    public function testEmailIsMatchedCaseInsensitively(): void
    {
        $this->overrideAuthConfig(['recovery_channels' => ['email']]);

        authRecovery::request(strtoupper(self::TEST_EMAIL));

        // A row was actually issued for the real contact — proof the
        // uppercase submission still resolved, not just that the response
        // looked the same as the not-found case above.
        $this->assertSame(1, $this->countRecoveryRows());
    }

    public function testEmailOnlyIssuesARowWhenTheAddressResolves(): void
    {
        $this->overrideAuthConfig(['recovery_channels' => ['email']]);

        authRecovery::request('nobody-with-this-address@example.test');
        $this->assertSame(0, $this->countRecoveryRows());

        authRecovery::request(self::TEST_EMAIL);
        $this->assertSame(1, $this->countRecoveryRows());
    }

    // -------------------------------------------------------------------------
    // Phone provider (code-based, needsCode() === true): the decoy this
    // whole ADR exists for.
    // -------------------------------------------------------------------------

    public function testPhoneResponseIsIdenticalWhetherOrNotTheNumberResolves(): void
    {
        $this->overrideAuthConfig(['recovery_channels' => ['phone']]);

        $found    = authRecovery::request(self::TEST_PHONE_RAW);
        $notFound = authRecovery::request('+7 999 999-99-99');

        $this->assertSame($found->needsCode, $notFound->needsCode);
        $this->assertSame($found->error, $notFound->error);
        $this->assertTrue($found->needsCode);
        $this->assertSame('', $found->error);
    }

    public function testPhoneAlwaysIssuesARowRealOrDecoy(): void
    {
        $this->overrideAuthConfig(['recovery_channels' => ['phone']]);

        authRecovery::request(self::TEST_PHONE_RAW);
        authRecovery::request('+7 999 999-99-99');

        // Both rows exist — one real (contact_id > 0), one a decoy
        // (contact_id = 0) — and both under their own hash, not colliding.
        $this->assertSame(2, $this->countRecoveryRows());

        $model = new authPasswordRecoveryModel();
        $rows  = $model->query('SELECT contact_id, code_hash FROM auth_password_recovery')->fetchAll();
        $contact_ids = array_map(static fn($r) => (int)$r['contact_id'], $rows);
        sort($contact_ids);
        $this->assertSame([0, $this->contact_id], $contact_ids);

        // The decoy's code_hash is a real hash, not empty — an empty value
        // would route it into the "no code needed" branch instead of the
        // shared one (decision 5 of the ADR).
        foreach ($rows as $row) {
            $this->assertNotSame('', $row['code_hash']);
        }
    }

    public function testWrongCodeExhaustsAttemptsIdenticallyForARealNumberAndADecoy(): void
    {
        $this->overrideAuthConfig(['recovery_channels' => ['phone']]);

        authRecovery::request(self::TEST_PHONE_RAW);
        for ($i = 0; $i < authPasswordRecoveryModel::MAX_ATTEMPTS; $i++) {
            $this->assertFalse(authRecovery::verifyCode('000000'));
        }
        // Fifth wrong guess deletes the row — same fate as the model-level
        // test in authPasswordRecoveryModelTest, exercised here through the
        // real provider instead of the model directly.
        $this->assertSame(0, $this->countRecoveryRows());
        authRecovery::clearHandle();

        authRecovery::request('+7 999 999-99-99');
        for ($i = 0; $i < authPasswordRecoveryModel::MAX_ATTEMPTS; $i++) {
            $this->assertFalse(authRecovery::verifyCode('000000'));
        }
        $this->assertSame(0, $this->countRecoveryRows());
    }

    /**
     * The otp_send resource cap (decision 5 of the ADR) must refuse a real
     * number and a decoy on the exact same request count, with the exact
     * same response — otherwise the anti-enumeration property closed at the
     * response-shape level in testPhoneResponseIsIdenticalWhetherOrNotThe
     * NumberResolves() above just moves one layer down: "how many requests
     * before it starts saying no" becomes the new oracle instead.
     * throttle_login_attempts is forced to 1 so the second request on the
     * same number is already over threshold, without looping five times.
     */
    public function testOtpSendThrottleRefusalIsIdenticalForARealNumberAndADecoy(): void
    {
        $this->overrideAuthConfig([
            'recovery_channels'        => ['phone'],
            'throttle_login_attempts'  => 1,
        ]);

        $foundFirst = authRecovery::request(self::TEST_PHONE_RAW);
        authRecovery::clearHandle();
        $notFoundFirst = authRecovery::request('+7 999 999-99-99');
        authRecovery::clearHandle();

        // The first request against each number still succeeds — the cap
        // hasn't tripped yet, so this is not itself a giveaway.
        $this->assertSame('', $foundFirst->error);
        $this->assertSame('', $notFoundFirst->error);

        // The second request against the SAME number each trips the cap —
        // same refusal, same request count, real or made up.
        $foundSecond    = authRecovery::request(self::TEST_PHONE_RAW);
        $notFoundSecond = authRecovery::request('+7 999 999-99-99');

        // Compared with the retry-after count masked out: the two requests
        // land in different, adjacent seconds of wall-clock time (real
        // request, then decoy request), so the two retryAfter values can
        // differ by the same one second authThrottle itself would give any
        // two ordinary requests a second apart — that is normal elapsed-time
        // rounding, not a distinguishing signal an attacker could act on.
        $mask = static fn(string $s): string => preg_replace('/\d+/', 'N', $s);

        $this->assertNotSame('', $foundSecond->error);
        $this->assertSame($mask($foundSecond->error), $mask($notFoundSecond->error));
        $this->assertFalse($foundSecond->needsCode);
        $this->assertFalse($notFoundSecond->needsCode);
    }

    public function testCorrectCodeCompletesToTheRealContact(): void
    {
        $this->overrideAuthConfig(['recovery_channels' => ['phone']]);

        authRecovery::request(self::TEST_PHONE_RAW);

        // The plain code only ever exists inside authPhoneRecoveryProvider's
        // own sendSms() call, unreachable from a black-box test — the row's
        // code_hash is overwritten with a known value instead, exactly the
        // one piece authProfileConfirmModelTest gets for free by reading
        // issue()'s own return value (not available here: authRecovery's
        // response never carries the code, by design — see authRecoveryResponse).
        $model = new authPasswordRecoveryModel();
        $row   = $model->getByField([
            'identifier_hash' => hash('sha256', self::TEST_PHONE_NORMALIZED),
            'channel'         => 'phone',
        ]);
        $this->assertNotNull($row);
        $model->updateById($row['id'], ['code_hash' => password_hash('123456', PASSWORD_DEFAULT)]);

        $this->assertTrue(authRecovery::verifyCode('123456'));
        $this->assertSame($this->contact_id, authRecovery::complete());

        // complete() consumes the row.
        $this->assertSame(0, $this->countRecoveryRows());
    }

    // -------------------------------------------------------------------------

    private function countRecoveryRows(): int
    {
        return (int)(new authPasswordRecoveryModel())->query('SELECT COUNT(*) AS c FROM auth_password_recovery')->fetchField('c');
    }

    private function createTestContact(string $email, string $phone): int
    {
        $contact_id = (new waContactModel())->insert([
            'name'            => 'Auth Recovery Test',
            'password'        => waContact::getPasswordHash('irrelevant-test-password'),
            'is_user'         => 1,
            'create_datetime' => date('Y-m-d H:i:s'),
        ]);

        (new waContactEmailsModel())->insert([
            'contact_id' => $contact_id,
            'email'      => $email,
            'sort'       => 0,
        ]);

        (new waContactDataModel())->insert([
            'contact_id' => $contact_id,
            'field'      => 'phone',
            'value'      => $phone,
            'ext'        => '',
            'sort'       => 0,
            'status'     => waContactDataModel::STATUS_UNKNOWN,
        ]);

        return $contact_id;
    }

    private function deleteTestContact(int $contact_id): void
    {
        if ($contact_id <= 0) {
            return;
        }
        (new waContactModel())->deleteById($contact_id);
        (new waContactEmailsModel())->deleteByField('contact_id', $contact_id);
        (new waContactDataModel())->deleteByField('contact_id', $contact_id);
    }
}
