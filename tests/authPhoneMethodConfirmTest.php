<?php
/**
 * @author Serge Rodovnichenko <serge@syrnik.com>
 * @copyright Serge Rodovnichenko, 2026
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * authPhoneMethod::verifyOtp() stamps the number confirmed on a correct code
 * — one of the five sites that write status = confirmed added for AUTH-51,
 * see docs/adr/005-value-confirmation.md. A successful OTP login IS the
 * proof: nothing else ever will have proven this number if this does not.
 *
 * Drives the private verifyOtp() directly via Reflection, seeding the
 * session state sendOtp() would have left — same reflection-on-private-method
 * approach authBackendCaptchaActionTest uses, and the only way to reach this
 * without an actual SMS gateway configured (authenticate() -> sendOtp() would
 * otherwise throw trying to send one).
 */
class authPhoneMethodConfirmTest extends TestCase
{
    use authTestContactFixtureTrait;

    protected function tearDown(): void
    {
        $this->tearDownTestContacts();
        wa()->getStorage()->del($this->sessionKey());
        parent::tearDown();
    }

    public function testCorrectCodeStampsThePhoneConfirmed(): void
    {
        $phone = '+7900' . random_int(1000000, 9999999);
        $contact = $this->createTestContact(['phone' => $phone]);
        $code = '123456';

        wa()->getStorage()->set($this->sessionKey(), [
            'contact_id' => $contact->getId(),
            'phone'      => $phone,
            'hash'       => password_hash($code, PASSWORD_DEFAULT),
            'expires'    => time() + 300,
            'attempts'   => 0,
        ]);

        $result = $this->verifyOtp($phone, $code);

        $this->assertSame($contact->getId(), $result);
        $this->assertTrue(authContactStatus::isPrimaryConfirmed('phone', $contact->getId()));
    }

    public function testWrongCodeDoesNotStampAnything(): void
    {
        $phone = '+7900' . random_int(1000000, 9999999);
        $contact = $this->createTestContact(['phone' => $phone]);

        wa()->getStorage()->set($this->sessionKey(), [
            'contact_id' => $contact->getId(),
            'phone'      => $phone,
            'hash'       => password_hash('123456', PASSWORD_DEFAULT),
            'expires'    => time() + 300,
            'attempts'   => 0,
        ]);

        try {
            $this->verifyOtp($phone, 'wrong-code');
            $this->fail('expected authMethodStepException');
        } catch (authMethodStepException $e) {
            // expected — a wrong guess re-asks for the code
        }

        $this->assertFalse(authContactStatus::isPrimaryConfirmed('phone', $contact->getId()));
    }

    // -------------------------------------------------------------------------

    private function sessionKey(): string
    {
        return (new ReflectionClass(authPhoneMethod::class))->getConstant('OTP_SESSION_KEY');
    }

    private function verifyOtp(string $phone, string $code): int
    {
        $method = new ReflectionMethod(authPhoneMethod::class, 'verifyOtp');
        $method->setAccessible(true);

        return $method->invoke(new authPhoneMethod(), $phone, $code);
    }
}
