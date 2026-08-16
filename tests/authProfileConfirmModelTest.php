<?php
/**
 * @author Serge Rodovnichenko <serge@syrnik.com>
 * @copyright Serge Rodovnichenko, 2026
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * authProfileConfirmModel — токен на смену логина: TTL, лимит попыток, "заявка одна на поле"
 *
 * Работает через authTestTemporaryTablesTrait поверх настоящей auth_profile_confirm — тест
 * не трогает боевые данные dev-БД (wa-config/db.php) и не оставляет мусора при упавшем assert-е.
 */
class authProfileConfirmModelTest extends TestCase
{
    use authTestTemporaryTablesTrait;

    private authProfileConfirmModel $model;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpTemporaryTables('auth_profile_confirm');
        $this->model = new authProfileConfirmModel();
    }

    protected function tearDown(): void
    {
        $this->tearDownTemporaryTables();
        parent::tearDown();
    }

    public function testIssueReplacesEarlierRequestForSameContactAndField(): void
    {
        // Two live tokens for one login would mean the older mail still works after the
        // visitor corrected a typo — issue() must drop the earlier request, not keep both.
        $first  = $this->model->issue(1, 'email', 'old@example.test', false);
        $second = $this->model->issue(1, 'email', 'new@example.test', false);

        $this->assertNull($this->model->getValid($first['token']));
        $row = $this->model->getValid($second['token']);
        $this->assertNotNull($row);
        $this->assertSame('new@example.test', $row['value']);
    }

    public function testIssueDoesNotReplaceRequestForADifferentField(): void
    {
        $email_request = $this->model->issue(1, 'email', 'new@example.test', false);
        $phone_request = $this->model->issue(1, 'phone', '+70000000000', true);

        $this->assertNotNull($this->model->getValid($email_request['token']));
        $this->assertNotNull($this->model->getValid($phone_request['token']));
    }

    public function testIssueWithCodeReturnsPlainCodeOnceAndStoresOnlyItsHash(): void
    {
        $result = $this->model->issue(1, 'phone', '+70000000000', true);

        $this->assertNotNull($result['code']);
        $this->assertMatchesRegularExpression('/^\d{6}$/', $result['code']);

        $row = $this->model->getValid($result['token']);
        $this->assertNotSame($result['code'], $row['code_hash']);
        $this->assertTrue(password_verify($result['code'], $row['code_hash']));
    }

    public function testIssueWithoutCodeStoresEmptyHash(): void
    {
        $result = $this->model->issue(1, 'email', 'new@example.test', false);

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
        $result = $this->model->issue(1, 'email', 'new@example.test', false);
        $this->backdateCreatedDatetime($result['token'], 2);

        $this->assertNull($this->model->getValid($result['token']));
        // The expired row must actually be gone, not merely rejected — otherwise
        // issue() for the same field would keep piling up dead rows forever.
        $this->assertNull($this->model->getByField('token', $result['token']));
    }

    public function testGetPendingDeletesAndRejectsExpiredRow(): void
    {
        $result = $this->model->issue(1, 'phone', '+70000000000', true);
        $this->backdateCreatedDatetime($result['token'], 2);

        $this->assertNull($this->model->getPending(1, 'phone'));
    }

    public function testGetPendingReturnsLiveRow(): void
    {
        $this->model->issue(1, 'phone', '+70000000000', true);

        $row = $this->model->getPending(1, 'phone');
        $this->assertNotNull($row);
        $this->assertSame('+70000000000', $row['value']);
        $this->assertNull($this->model->getPending(1, 'email'));
    }

    public function testVerifyCodeSpendsAnAttemptOnAWrongGuess(): void
    {
        $result = $this->model->issue(1, 'phone', '+70000000000', true);
        $row    = $this->model->getValid($result['token']);

        $this->assertFalse($this->model->verifyCode($row, 'wrong-code'));
        $this->assertSame(4, $this->model->attemptsLeft($this->model->getValid($result['token'])));
    }

    public function testVerifyCodeSucceedsWithoutSpendingAnAttempt(): void
    {
        $result = $this->model->issue(1, 'phone', '+70000000000', true);
        $row    = $this->model->getValid($result['token']);

        $this->assertTrue($this->model->verifyCode($row, $result['code']));
        $this->assertSame(5, $this->model->attemptsLeft($this->model->getValid($result['token'])));
    }

    public function testVerifyCodeDeletesRowAfterFifthWrongGuess(): void
    {
        $result = $this->model->issue(1, 'phone', '+70000000000', true);

        for ($i = 0; $i < 4; $i++) {
            $row = $this->model->getValid($result['token']);
            $this->assertFalse($this->model->verifyCode($row, 'wrong-code'));
        }
        $this->assertSame(1, $this->model->attemptsLeft($this->model->getValid($result['token'])));

        // Fifth guess: row must be thrown away, not merely emptied of attempts —
        // a six-digit code within an hour is otherwise brute-forceable.
        $row = $this->model->getValid($result['token']);
        $this->assertFalse($this->model->verifyCode($row, 'wrong-code'));
        $this->assertNull($this->model->getValid($result['token']));
    }

    public function testDeleteExpiredRemovesOnlyOldRows(): void
    {
        $fresh   = $this->model->issue(1, 'email', 'fresh@example.test', false);
        $expired = $this->model->issue(2, 'email', 'expired@example.test', false);
        $this->backdateCreatedDatetime($expired['token'], 2);

        $this->model->deleteExpired();

        $this->assertNotNull($this->model->getByField('token', $fresh['token']));
        $this->assertNull($this->model->getByField('token', $expired['token']));
    }

    // -------------------------------------------------------------------------

    /**
     * Pushes a row's created_datetime $hours_ago into the past, past the model's
     * 1-hour TTL, so getValid()/getPending()/deleteExpired() treat it as expired
     * without waiting an hour.
     */
    private function backdateCreatedDatetime(string $token, int $hours_ago): void
    {
        $this->model->updateByField(
            'token',
            $token,
            ['created_datetime' => date('Y-m-d H:i:s', time() - $hours_ago * 3600)]
        );
    }
}
