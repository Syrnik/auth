<?php
/**
 * @author Serge Rodovnichenko <serge@syrnik.com>
 * @copyright Serge Rodovnichenko, 2026
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * authSignupConfirmModel — registration email-confirmation tokens: TTL and,
 * since AUTH-51, the address a token's link was actually mailed to.
 *
 * See docs/adr/005-value-confirmation.md: authFrontendConfirmAction stamps
 * this stored address confirmed, not whatever sits at sort = 0 when the link
 * is clicked — the visitor may have changed it in between, and stamping the
 * current one would confirm an address this token never proved.
 */
class authSignupConfirmModelTest extends TestCase
{
    use authTestTemporaryTablesTrait;

    private authSignupConfirmModel $model;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpTemporaryTables('auth_signup_confirm');
        $this->model = new authSignupConfirmModel();
    }

    protected function tearDown(): void
    {
        $this->tearDownTemporaryTables();
        parent::tearDown();
    }

    public function testCreateTokenStoresTheMailedAddress(): void
    {
        $token = $this->model->createToken(1, 'new@example.test');

        $row = $this->model->getValid($token);
        $this->assertSame('new@example.test', $row['email']);
    }

    public function testCreateTokenDefaultsEmailToEmptyString(): void
    {
        $token = $this->model->createToken(1);

        $row = $this->model->getValid($token);
        $this->assertSame('', $row['email']);
    }

    public function testGetValidReturnsNullForUnknownToken(): void
    {
        $this->assertNull($this->model->getValid('there-is-no-such-token'));
    }

    public function testGetValidDeletesAndRejectsExpiredRow(): void
    {
        $token = $this->model->createToken(1, 'new@example.test');
        $this->model->updateByField('token', $token, [
            'created_datetime' => date('Y-m-d H:i:s', time() - 25 * 3600),
        ]);

        $this->assertNull($this->model->getValid($token));
        $this->assertNull($this->model->getByField('token', $token));
    }
}
