<?php
/**
 * @author Serge Rodovnichenko <serge@syrnik.com>
 * @copyright Serge Rodovnichenko, 2026
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * AUTH-660: how authFrontendChallengeAction turns a challenge plugin's
 * verify() outcome into the refusal the form is shown again with. The
 * private static verifyChallenge() is reached through Reflection, same as
 * authBackendCaptchaActionTest — the rest of execute() is session/request
 * plumbing around it.
 */
class authFrontendChallengeActionTest extends TestCase
{
    public function testPassingChallengeHasNoRefusal(): void
    {
        $this->assertNull($this->verify(fn() => true));
    }

    public function testWrongResponseIsIncorrectCodeWithoutRetryAfter(): void
    {
        $this->assertSame(['error' => _w('Incorrect code.')], $this->verify(fn() => false));
    }

    public function testThrottledChallengeShowsTheBlockedMessageAndRetryAfter(): void
    {
        $refusal = $this->verify(function () {
            throw new authChallengeThrottledException(42);
        });

        $this->assertSame(['error' => authThrottle::blockedMessage(42), 'retry_after' => 42], $refusal);
    }

    public function testNonPositiveRetryAfterIsClampedToOneSecond(): void
    {
        $refusal = $this->verify(function () {
            throw new authChallengeThrottledException(0);
        });

        $this->assertSame(1, $refusal['retry_after']);
        $this->assertSame(authThrottle::blockedMessage(1), $refusal['error']);
    }

    /**
     * Only the throttle exception becomes a visitor-facing message — an
     * authGuardException (or anything else) from a plugin is not part of the
     * challenge contract and must not be swallowed into the form.
     */
    public function testOtherExceptionsPropagate(): void
    {
        $this->expectException(authGuardException::class);
        $this->verify(function () {
            throw new authGuardException('plugin bug');
        });
    }

    private function verify(callable $outcome): ?array
    {
        $challenge = new class($outcome) implements authChallenge {
            private $outcome;

            public function __construct(callable $outcome)
            {
                $this->outcome = $outcome;
            }

            public function isRequired(int $contact_id): bool
            {
                return true;
            }

            public function verify(array $params): bool
            {
                return ($this->outcome)();
            }

            public function getId(): string
            {
                return 'testchallenge';
            }
        };

        $method = new ReflectionMethod(authFrontendChallengeAction::class, 'verifyChallenge');
        $method->setAccessible(true);
        return $method->invoke(null, $challenge, []);
    }
}
