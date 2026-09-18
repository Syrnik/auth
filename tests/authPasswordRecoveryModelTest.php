<?php
/**
 * @author Serge Rodovnichenko <serge@syrnik.com>
 * @copyright Serge Rodovnichenko, 2026
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * authPasswordRecoveryModel — one pending recovery row per (identifier hash,
 * channel): replace-on-reissue, TTL, code attempts, and specifically the
 * decoy-collision guard docs/adr/004-recovery-channels.md decision 6 exists
 * for (the unique key is on identifier_hash, not contact_id, precisely so
 * two decoy rows — both carrying contact_id = 0 — never collide).
 *
 * Works through authTestTemporaryTablesTrait over the real auth_password_recovery
 * table — no boilerplate contact rows needed, contact_id is just an int here.
 */
class authPasswordRecoveryModelTest extends TestCase
{
    use authTestTemporaryTablesTrait;

    private authPasswordRecoveryModel $model;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpTemporaryTables('auth_password_recovery');
        $this->model = new authPasswordRecoveryModel();
    }

    protected function tearDown(): void
    {
        $this->tearDownTemporaryTables();
        parent::tearDown();
    }

    public function testIssueReplacesEarlierRequestForSameIdentifierAndChannel(): void
    {
        $first  = $this->model->issue(1, 'email', 'hash-a', false);
        $second = $this->model->issue(1, 'email', 'hash-a', false);

        $this->assertNull($this->model->getValid($first['token']));
        $this->assertNotNull($this->model->getValid($second['token']));
    }

    public function testIssueDoesNotReplaceARequestForADifferentIdentifierHash(): void
    {
        $one = $this->model->issue(1, 'email', 'hash-a', false);
        $two = $this->model->issue(1, 'email', 'hash-b', false);

        $this->assertNotNull($this->model->getValid($one['token']));
        $this->assertNotNull($this->model->getValid($two['token']));
    }

    public function testIssueDoesNotReplaceARequestForADifferentChannel(): void
    {
        $email = $this->model->issue(1, 'email', 'same-hash', false);
        $phone = $this->model->issue(1, 'phone', 'same-hash', true);

        $this->assertNotNull($this->model->getValid($email['token']));
        $this->assertNotNull($this->model->getValid($phone['token']));
    }

    /**
     * The scenario decision 6 of the ADR calls out by name: two decoy rows
     * (both contact_id = 0, one per non-existent identifier) must never
     * collide with each other just because they share the same fake
     * contact_id — only a shared identifier_hash should replace a row.
     */
    public function testTwoDecoyRowsForDifferentIdentifiersDoNotCollide(): void
    {
        $decoy_one = $this->model->issue(0, 'phone', 'decoy-hash-a', true);
        $decoy_two = $this->model->issue(0, 'phone', 'decoy-hash-b', true);

        $this->assertNotNull($this->model->getValid($decoy_one['token']));
        $this->assertNotNull($this->model->getValid($decoy_two['token']));
    }

    public function testIssueWithCodeReturnsPlainCodeOnceAndStoresOnlyItsHash(): void
    {
        $result = $this->model->issue(1, 'phone', 'hash-a', true);

        $this->assertNotNull($result['code']);
        $this->assertMatchesRegularExpression('/^\d{6}$/', $result['code']);

        $row = $this->model->getValid($result['token']);
        $this->assertNotSame($result['code'], $row['code_hash']);
        $this->assertTrue(password_verify($result['code'], $row['code_hash']));
    }

    public function testIssueWithoutCodeStoresEmptyHash(): void
    {
        $result = $this->model->issue(1, 'email', 'hash-a', false);

        $this->assertNull($result['code']);
        $row = $this->model->getValid($result['token']);
        $this->assertSame('', $row['code_hash']);
        $this->assertFalse($this->model->needsCode(['code_hash' => '']));
        $this->assertTrue($this->model->needsCode(['code_hash' => 'x']));
    }

    public function testGetValidReturnsNullForUnknownToken(): void
    {
        $this->assertNull($this->model->getValid('there-is-no-such-token'));
        $this->assertNull($this->model->getValid(''));
    }

    public function testGetValidDeletesAndRejectsExpiredRow(): void
    {
        $result = $this->model->issue(1, 'email', 'hash-a', false, 3600);
        $this->backdateCreatedDatetime($result['token'], -7200);

        $this->assertNull($this->model->getValid($result['token']));
        // The expired row must actually be gone, not merely rejected — otherwise
        // issue() for the same identifier would keep piling up dead rows forever.
        $this->assertNull($this->model->getByField('token', $result['token']));
    }

    public function testVerifyCodeSpendsAnAttemptOnAWrongGuess(): void
    {
        $result = $this->model->issue(1, 'phone', 'hash-a', true);
        $row    = $this->model->getValid($result['token']);

        $this->assertFalse($this->model->verifyCode($row, 'wrong-code'));
        $this->assertSame(4, $this->model->attemptsLeft($this->model->getValid($result['token'])));
    }

    public function testVerifyCodeSucceedsWithoutSpendingAnAttempt(): void
    {
        $result = $this->model->issue(1, 'phone', 'hash-a', true);
        $row    = $this->model->getValid($result['token']);

        $this->assertTrue($this->model->verifyCode($row, $result['code']));
        $this->assertSame(5, $this->model->attemptsLeft($this->model->getValid($result['token'])));
    }

    /**
     * A decoy row's code fails exactly like a real one's — same schedule,
     * same fate. There is no plain code to test with here (a decoy's is
     * generated and hashed but never delivered anywhere), which is itself
     * the point: verifyCode() cannot tell a decoy apart from a real row it
     * has no code for either.
     */
    public function testVerifyCodeDeletesRowAfterFifthWrongGuessRealOrDecoy(): void
    {
        foreach ([1, 0] as $contact_id) {
            $result = $this->model->issue($contact_id, 'phone', 'hash-' . $contact_id, true);

            for ($i = 0; $i < authPasswordRecoveryModel::MAX_ATTEMPTS - 1; $i++) {
                $row = $this->model->getValid($result['token']);
                $this->assertFalse($this->model->verifyCode($row, 'wrong-code'));
            }
            $this->assertSame(1, $this->model->attemptsLeft($this->model->getValid($result['token'])));

            $row = $this->model->getValid($result['token']);
            $this->assertFalse($this->model->verifyCode($row, 'wrong-code'));
            $this->assertNull($this->model->getValid($result['token']));
        }
    }

    public function testDeleteByContactRemovesOnlyThatContactsRows(): void
    {
        $mine   = $this->model->issue(1, 'email', 'hash-mine', false);
        $theirs = $this->model->issue(2, 'email', 'hash-theirs', false);

        $this->model->deleteByContact(1);

        $this->assertNull($this->model->getValid($mine['token']));
        $this->assertNotNull($this->model->getValid($theirs['token']));
    }

    public function testDeleteByContactNeverTouchesDecoyRows(): void
    {
        // deleteByContact(0) must be a no-op — 0 is not a real contact, it is
        // every decoy's shared id, and wiping "contact 0" would delete every
        // decoy currently pending across every identifier.
        $decoy = $this->model->issue(0, 'phone', 'decoy-hash', true);

        $this->model->deleteByContact(0);

        $this->assertNotNull($this->model->getValid($decoy['token']));
    }

    public function testDeleteExpiredRemovesOnlyOldRows(): void
    {
        $fresh   = $this->model->issue(1, 'email', 'hash-fresh', false);
        $expired = $this->model->issue(2, 'email', 'hash-expired', false, 3600);
        $this->backdateCreatedDatetime($expired['token'], -7200);

        $this->model->deleteExpired();

        $this->assertNotNull($this->model->getByField('token', $fresh['token']));
        $this->assertNull($this->model->getByField('token', $expired['token']));
    }

    // -------------------------------------------------------------------------

    /**
     * Pushes a row's created/expire datetimes $seconds_offset into the past
     * (negative) so getValid()/deleteExpired() treat it as expired without
     * waiting for its real TTL.
     */
    private function backdateCreatedDatetime(string $token, int $seconds_offset): void
    {
        $this->model->updateByField(
            'token',
            $token,
            [
                'created_datetime' => date('Y-m-d H:i:s', time() + $seconds_offset),
                'expire_datetime'  => date('Y-m-d H:i:s', time() + $seconds_offset),
            ]
        );
    }
}
