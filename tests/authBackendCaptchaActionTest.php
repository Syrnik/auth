<?php
/**
 * @author Serge Rodovnichenko <serge@syrnik.com>
 * @copyright Serge Rodovnichenko, 2026
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * authBackendCaptchaAction's POST-value clamping (AUTH-49's "Brute-force
 * protection" backend screen). Reached through Reflection, the same way
 * authTestConfigOverrideTrait reaches authConfig's private $saved — both
 * methods are pure (no waRequest/DB access), so this is the cheapest useful
 * check that a malformed or missing field falls back to something sane
 * instead of leaving the section unsaved or storing garbage.
 */
class authBackendCaptchaActionTest extends TestCase
{
    private authBackendCaptchaAction $action;

    protected function setUp(): void
    {
        parent::setUp();
        $this->action = new authBackendCaptchaAction();
    }

    /** @dataProvider preparePositiveIntProvider */
    public function testPreparePositiveInt($value, int $default, int $min, ?int $max, int $expected): void
    {
        $method = new ReflectionMethod(authBackendCaptchaAction::class, 'preparePositiveInt');
        $method->setAccessible(true);
        $this->assertSame($expected, $method->invoke($this->action, $value, $default, $min, $max));
    }

    public function preparePositiveIntProvider(): array
    {
        return [
            'missing field falls back to default'      => [null, 900, 1, null, 900],
            'empty string falls back to default'        => ['', 900, 1, null, 900],
            'a normal value is kept'                    => ['5', 5, 1, null, 5],
            'below the floor is clamped up to it'       => ['-5', 2, 0, null, 0],
            'zero is allowed when the floor is zero'    => ['0', 2, 0, null, 0],
            'zero is clamped when the floor is one'     => ['0', 5, 1, null, 1],
            'non-numeric input casts to zero, then clamps' => ['abc', 5, 1, null, 1],
            // authThrottleDbStore's lazy sweep assumes no configured window
            // exceeds RETENTION_SECONDS — an unclamped window/lockout could
            // let the sweep delete a row before the hit() that just wrote it
            // returns (see authThrottleDbStore's own docblock).
            'above the ceiling is clamped down to it'   => ['999999', 900, 1, 86400, 86400],
            'within the ceiling is kept'                => ['3600', 900, 1, 86400, 3600],
        ];
    }

    /** @dataProvider prepareCaptchaModeProvider */
    public function testPrepareCaptchaMode(string $input, string $expected): void
    {
        $method = new ReflectionMethod(authBackendCaptchaAction::class, 'prepareCaptchaMode');
        $method->setAccessible(true);
        $this->assertSame($expected, $method->invoke($this->action, $input));
    }

    public function prepareCaptchaModeProvider(): array
    {
        return [
            'off is kept'                          => ['off', 'off'],
            'always is kept'                       => ['always', 'always'],
            'after_n is kept'                      => ['after_n', 'after_n'],
            'empty string falls back to always'    => ['', 'always'],
            'garbage falls back to always'         => ['garbage', 'always'],
        ];
    }
}
