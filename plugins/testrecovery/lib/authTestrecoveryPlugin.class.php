<?php

/**
 * Test fixture for the authRecoveryProvider machinery (like testguard for
 * guards, testmulti for multi-instance methods): claims any identifier
 * prefixed 'test:', a shape no real email or phone number can ever have,
 * so tests can control exactly which requests this provider picks up
 * without colliding with the built-in email/phone providers.
 */
class authTestrecoveryPlugin extends authPlugin implements authRecoveryProvider
{
    public function getId(): string
    {
        return 'testrecovery';
    }

    public function claims(string $identifier): bool
    {
        return str_starts_with($identifier, 'test:');
    }

    public function start(string $identifier): authRecoveryHandle
    {
        return new authRecoveryHandle($this->getId(), 'test-token-' . substr($identifier, 5));
    }

    public function needsCode(): bool
    {
        return false;
    }

    public function verifyCode(authRecoveryHandle $handle, string $code): bool
    {
        return false;
    }

    public function complete(authRecoveryHandle $handle): ?int
    {
        return null;
    }
}
