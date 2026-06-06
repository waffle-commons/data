<?php

declare(strict_types=1);

namespace WaffleTests\Commons\Data\Fixture;

use Waffle\Commons\Contracts\Auth\SecurityContextInterface;
use Waffle\Commons\Contracts\Auth\UserIdentityInterface;

/**
 * Controllable {@see SecurityContextInterface} double for the Firestore Rule 3
 * (authentication-gate) tests: construct it authenticated or anonymous.
 */
final class FakeSecurityContext implements SecurityContextInterface
{
    private ?UserIdentityInterface $identity;

    private ?string $clientIp;

    public function __construct(?UserIdentityInterface $identity = null, ?string $clientIp = null)
    {
        $this->identity = $identity;
        $this->clientIp = $clientIp;
    }

    /** Build an authenticated context for the given subject. */
    public static function authenticatedAs(string $subject): self
    {
        return new self(new FakeIdentity($subject), '203.0.113.7');
    }

    /** Build an anonymous (unauthenticated) context. */
    public static function anonymous(): self
    {
        return new self();
    }

    #[\Override]
    public function authenticate(UserIdentityInterface $identity, ?string $clientIp = null): void
    {
        $this->identity = $identity;
        $this->clientIp = $clientIp;
    }

    #[\Override]
    public function isAuthenticated(): bool
    {
        return $this->identity !== null;
    }

    #[\Override]
    public function getIdentity(): ?UserIdentityInterface
    {
        return $this->identity;
    }

    #[\Override]
    public function getClientIp(): ?string
    {
        return $this->clientIp;
    }

    #[\Override]
    public function reset(): void
    {
        $this->identity = null;
        $this->clientIp = null;
    }
}
