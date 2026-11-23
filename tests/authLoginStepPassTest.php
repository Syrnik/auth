<?php
/**
 * @author Serge Rodovnichenko <serge@syrnik.com>
 * @copyright Serge Rodovnichenko, 2026
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * AUTH-856: the captcha is asked on the POST that starts a login step, not
 * again on the ones that only continue it. Covers the session pass itself,
 * authPhoneMethod's answers to authSteppedMethod, and the controller's
 * private hasStepPass() — the one place that combines them — reached
 * through Reflection the way authFrontendChallengeActionTest does.
 */
class authLoginStepPassTest extends TestCase
{
    private const NUMBER = '79001234567';

    protected function tearDown(): void
    {
        authLoginStepPass::clear();
        parent::tearDown();
    }

    // --- authLoginStepPass ---------------------------------------------------

    public function testNoPassByDefault(): void
    {
        $this->assertFalse(authLoginStepPass::isLive('phone', self::NUMBER));
    }

    public function testPassIsLiveForItsMethodAndSubject(): void
    {
        authLoginStepPass::begin('phone', self::NUMBER);

        $this->assertTrue(authLoginStepPass::isLive('phone', self::NUMBER));
    }

    public function testPassDoesNotCoverAnotherSubject(): void
    {
        authLoginStepPass::begin('phone', self::NUMBER);

        $this->assertFalse(authLoginStepPass::isLive('phone', '79007654321'));
    }

    public function testPassDoesNotCoverAnotherMethodInstance(): void
    {
        authLoginStepPass::begin('phone', self::NUMBER);

        $this->assertFalse(authLoginStepPass::isLive('phone:2', self::NUMBER));
    }

    public function testExpiredPassIsNotLiveAndIsDropped(): void
    {
        authLoginStepPass::begin('phone', self::NUMBER);
        $pass = wa()->getStorage()->get(authLoginStepPass::SESSION_KEY);
        $pass['expires'] = time() - 1;
        wa()->getStorage()->set(authLoginStepPass::SESSION_KEY, $pass);

        $this->assertFalse(authLoginStepPass::isLive('phone', self::NUMBER));
        $this->assertNull(wa()->getStorage()->get(authLoginStepPass::SESSION_KEY));
    }

    public function testPassLastsTheCodeTtl(): void
    {
        $before = time();
        authLoginStepPass::begin('phone', self::NUMBER);
        $pass = wa()->getStorage()->get(authLoginStepPass::SESSION_KEY);

        $this->assertGreaterThanOrEqual($before + authLoginStepPass::TTL_SECONDS, $pass['expires']);
        $this->assertLessThanOrEqual(time() + authLoginStepPass::TTL_SECONDS, $pass['expires']);
    }

    public function testClearDropsThePass(): void
    {
        authLoginStepPass::begin('phone', self::NUMBER);
        authLoginStepPass::clear();

        $this->assertFalse(authLoginStepPass::isLive('phone', self::NUMBER));
    }

    public function testEmptySubjectIssuesNoPass(): void
    {
        authLoginStepPass::begin('phone', '');

        $this->assertNull(wa()->getStorage()->get(authLoginStepPass::SESSION_KEY));
        $this->assertFalse(authLoginStepPass::isLive('phone', ''));
    }

    // --- authPhoneMethod as authSteppedMethod --------------------------------

    public function testPhoneSubjectIsTheNormalizedNumber(): void
    {
        $method = new authPhoneMethod();

        $this->assertSame(
            $method->getStepSubject(['phone' => self::NUMBER]),
            $method->getStepSubject(['phone' => ' +7 (900) 123-45-67 '])
        );
    }

    /**
     * @dataProvider phoneContinuationProvider
     */
    public function testPhoneContinuationIsOnlyACodeVerification(array $post, bool $expected): void
    {
        $this->assertSame($expected, (new authPhoneMethod())->isStepContinuation($post));
    }

    public function phoneContinuationProvider(): array
    {
        return [
            'code typed'                => [['phone' => self::NUMBER, 'otp_code' => '123456'], true],
            'number only: first send'   => [['phone' => self::NUMBER], false],
            'empty code: a send again'  => [['phone' => self::NUMBER, 'otp_code' => '  '], false],
            'resend with a typed code'  => [['phone' => self::NUMBER, 'otp_code' => '123456', 'otp_resend' => '1'], false],
        ];
    }

    // --- authLoginController::hasStepPass() ---------------------------------

    public function testContinuationWithLivePassSkipsTheCaptcha(): void
    {
        authLoginStepPass::begin('phone', self::NUMBER);

        $this->assertTrue($this->hasStepPass(new authPhoneMethod(), 'phone', [
            'phone'    => '+7 900 123-45-67',
            'otp_code' => '123456',
        ]));
    }

    public function testContinuationWithoutAPassKeepsTheCaptcha(): void
    {
        $this->assertFalse($this->hasStepPass(new authPhoneMethod(), 'phone', [
            'phone'    => self::NUMBER,
            'otp_code' => '123456',
        ]));
    }

    public function testResendKeepsTheCaptchaEvenWithALivePass(): void
    {
        authLoginStepPass::begin('phone', self::NUMBER);

        $this->assertFalse($this->hasStepPass(new authPhoneMethod(), 'phone', [
            'phone'      => self::NUMBER,
            'otp_code'   => '123456',
            'otp_resend' => '1',
        ]));
        $this->assertFalse($this->hasStepPass(new authPhoneMethod(), 'phone', [
            'phone' => self::NUMBER,
        ]));
    }

    public function testAnotherNumberKeepsTheCaptcha(): void
    {
        authLoginStepPass::begin('phone', self::NUMBER);

        $this->assertFalse($this->hasStepPass(new authPhoneMethod(), 'phone', [
            'phone'    => '79007654321',
            'otp_code' => '123456',
        ]));
    }

    public function testMethodWithoutStepsNeverSkipsTheCaptcha(): void
    {
        authLoginStepPass::begin('email', 'user@example.com');

        $this->assertFalse($this->hasStepPass(new authEmailMethod(), 'email', [
            'email'    => 'user@example.com',
            'password' => 'secret',
        ]));
    }

    private function hasStepPass(authMethod $method, string $method_id, array $post): bool
    {
        $class      = new ReflectionClass(authLoginController::class);
        $controller = $class->newInstanceWithoutConstructor();
        $call       = $class->getMethod('hasStepPass');
        $call->setAccessible(true);
        return $call->invoke($controller, $method, $method_id, $post);
    }
}
