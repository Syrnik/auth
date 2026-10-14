<?php
/**
 * @author Serge Rodovnichenko <serge@syrnik.com>
 * @copyright Serge Rodovnichenko, 2026
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * authCsrf — the anonymous forms' double-submit token (AUTH-573, see
 * docs/adr/007-anonymous-csrf.md). Drives it through the superglobals
 * waRequest reads; the response cookie itself is not observable from the
 * CLI (headers are already sent), $_COOKIE is what the same request sees.
 */
class authCsrfTest extends TestCase
{
    private array $cookie_backup;
    private array $post_backup;
    private array $server_backup;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cookie_backup = $_COOKIE;
        $this->post_backup   = $_POST;
        $this->server_backup = $_SERVER;
        unset($_COOKIE['_csrf'], $_POST['_csrf'], $_SERVER['HTTP_X_CSRF_TOKEN']);
    }

    protected function tearDown(): void
    {
        $_COOKIE = $this->cookie_backup;
        $_POST   = $this->post_backup;
        $_SERVER = $this->server_backup;
        parent::tearDown();
    }

    public function testEmptyFieldAgainstAbsentCookieIsRejected(): void
    {
        // The framework's own '' == '' hole for anonymous visitors.
        $_POST['_csrf'] = '';
        $this->assertFalse(authCsrf::isValid());

        unset($_POST['_csrf']);
        $this->assertFalse(authCsrf::isValid());
    }

    public function testCookieWithoutFieldIsRejected(): void
    {
        $_COOKIE['_csrf'] = 'abc123';
        $this->assertFalse(authCsrf::isValid());
    }

    public function testFieldWithoutCookieIsRejected(): void
    {
        // A token lifted from someone else's form, posted from a browser
        // that was never issued one.
        $_POST['_csrf'] = 'abc123';
        $this->assertFalse(authCsrf::isValid());
    }

    public function testMismatchIsRejected(): void
    {
        $_COOKIE['_csrf'] = 'abc123';
        $_POST['_csrf']   = 'abc124';
        $this->assertFalse(authCsrf::isValid());
    }

    public function testNonStringFieldIsRejected(): void
    {
        $_COOKIE['_csrf'] = 'abc123';
        $_POST['_csrf']   = ['abc123'];
        $this->assertFalse(authCsrf::isValid());
    }

    public function testMatchingFieldIsAccepted(): void
    {
        $_COOKIE['_csrf'] = 'abc123';
        $_POST['_csrf']   = 'abc123';
        $this->assertTrue(authCsrf::isValid());
    }

    public function testMatchingHeaderIsAccepted(): void
    {
        $_COOKIE['_csrf']              = 'abc123';
        $_SERVER['HTTP_X_CSRF_TOKEN'] = 'abc123';
        $this->assertTrue(authCsrf::isValid());
    }

    public function testTokenIsIssuedOnceAndVisibleToTheSameRequest(): void
    {
        $token = authCsrf::token();

        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $token);
        $this->assertSame($token, $_COOKIE['_csrf']);
        $this->assertSame($token, authCsrf::token());
        $this->assertSame($token, authHelper::getCsrfToken());
    }

    public function testExistingCookieIsNeverReplaced(): void
    {
        // waAuthUser's own value for a signed-in visitor — whatever its
        // format, it stays the token.
        $_COOKIE['_csrf'] = 'framework.issued.value';
        $this->assertSame('framework.issued.value', authCsrf::token());
    }

    public function testIssuedTokenThenRoundTrips(): void
    {
        $_POST['_csrf'] = authCsrf::token();
        $this->assertTrue(authCsrf::isValid());
    }
}
