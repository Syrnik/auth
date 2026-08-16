<?php
/**
 * @author Serge Rodovnichenko <serge@syrnik.com>
 * @copyright Serge Rodovnichenko, 2026
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * authHelper::localRedirectUrl() и распознавание методов входа — без БД и без конфига
 *
 * localRedirectUrl() — единственная защита от open-redirect на пути "войти → вернуться на
 * goal_url", а goal_url приходит от посетителя. Кейсы ниже — ровно те, что перечислены в
 * docblock'е метода (authHelper.class.php); если кто-то ослабит проверку, здесь это увидят
 * раньше, чем в проде.
 *
 * methodIsOAuth()/methodName() читают константы класса метода и не трогают ни БД, ни
 * authConfig — поэтому им не нужны дублёры, годятся настоящие authXxxMethod.
 */
class authHelperTest extends TestCase
{
    /** @dataProvider localRedirectUrlProvider */
    public function testLocalRedirectUrl(string $url, string $expected, string $fallback = '/'): void
    {
        $this->assertSame($expected, authHelper::localRedirectUrl($url, $fallback));
    }

    public function localRedirectUrlProvider(): array
    {
        return [
            'empty string falls back'               => ['', '/'],
            'root path is kept'                      => ['/my/', '/my/'],
            'protocol-relative // is rejected'        => ['//evil.com/', '/'],
            'backslash trick /\\ is rejected'         => ['/\\evil.com/', '/'],
            'absolute URL to another host is rejected' => ['https://evil.com/x', '/'],
            'javascript: scheme is rejected'          => ['javascript:alert(1)', '/'],
            'CR injection is rejected'                => ["/my/\r\nSet-Cookie: x=1", '/'],
            'LF injection is rejected'                => ["/my/\nLocation: evil.com", '/'],
            // NUL only rejects when it survives trim() — trim()'s default char list strips
            // NUL like whitespace, so a NUL must sit mid-string (not at the very edges,
            // where trim() would quietly remove it before strpbrk() ever sees it).
            'embedded NUL byte is rejected'           => ["/my/\0page", '/'],
            'custom fallback is honored'               => ['//evil.com/', '/custom-fallback/', '/custom-fallback/'],
        ];
    }

    public function testLocalRedirectUrlKeepsAbsoluteUrlOnCurrentHost(): void
    {
        $previous_host = $_SERVER['HTTP_HOST'] ?? null;
        $_SERVER['HTTP_HOST'] = 'example.test';

        try {
            $this->assertSame(
                'https://example.test/my/',
                authHelper::localRedirectUrl('https://example.test/my/')
            );
            // Host comparison is case-insensitive — browsers treat host names that way.
            $this->assertSame(
                'https://EXAMPLE.test/my/',
                authHelper::localRedirectUrl('https://EXAMPLE.test/my/')
            );
        } finally {
            if ($previous_host === null) {
                unset($_SERVER['HTTP_HOST']);
            } else {
                $_SERVER['HTTP_HOST'] = $previous_host;
            }
        }
    }

    public function testEmailMethodIsNotOAuthAndUsesPassword(): void
    {
        $method = new authEmailMethod();
        $this->assertFalse(authHelper::methodIsOAuth($method));
        $this->assertSame('Email / пароль', authHelper::methodName($method));
    }

    public function testWaidMethodIsOAuth(): void
    {
        $this->assertTrue(authHelper::methodIsOAuth(new authWaidMethod()));
    }

    public function testPhoneMethodIsNotOAuth(): void
    {
        $this->assertFalse(authHelper::methodIsOAuth(new authPhoneMethod()));
    }

    public function testUnknownObjectIsNotOAuth(): void
    {
        $this->assertFalse(authHelper::methodIsOAuth(new stdClass()));
        $this->assertSame('', authHelper::methodName(new stdClass()));
    }
}
