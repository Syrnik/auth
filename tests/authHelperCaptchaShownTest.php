<?php
/**
 * @author Serge Rodovnichenko <serge@syrnik.com>
 * @copyright Serge Rodovnichenko, 2026
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * authHelper::loginCaptchaWidget()/isCaptchaShown() — the marker that tells
 * authLoginController whether the form a POST came from actually had a
 * captcha widget on it (AUTH-49).
 *
 * Exists because the login form's GET-time captcha decision (IP counter
 * only — the identifier isn't known yet) and its POST-time decision (both
 * counters) can disagree under captcha_mode:after_n. Without this marker,
 * a POST that crossed the threshold only after the form was rendered would
 * run verifyCaptcha() against a request that never had a token, fail it,
 * and charge the visitor's IP a hit before their password was ever checked.
 */
class authHelperCaptchaShownTest extends TestCase
{
    public function testWidgetNotRequiredYieldsNoMarkerAndReadsAsNotShown(): void
    {
        // No captcha_plugin configured for the current domain in this test
        // run, so getCaptchaWidget() is '' regardless of $required — the
        // case every install starts in.
        $widget = authHelper::loginCaptchaWidget(false);
        $this->assertSame('', $widget);
        $this->assertFalse(authHelper::isCaptchaShown([]));
    }

    public function testMarkerFieldNameIsStableAcrossHelperAndPost(): void
    {
        // isCaptchaShown() must recognize exactly the field name the
        // template/JS actually emit — a typo in either place would make
        // every captcha-required login silently loop.
        $this->assertTrue(authHelper::isCaptchaShown([authHelper::CAPTCHA_SHOWN_FIELD => '1']));
        $this->assertFalse(authHelper::isCaptchaShown(['_captcha_shown' => '0']));
        $this->assertFalse(authHelper::isCaptchaShown(['some_other_field' => '1']));
    }
}
