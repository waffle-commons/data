<?php

declare(strict_types=1);

namespace WaffleTests\Commons\Data\Fixture;

use Waffle\Commons\Contracts\Auth\UserIdentityInterface;

/**
 * Minimal verified-identity double for the Firestore auth-gate tests.
 */
final class FakeIdentity implements UserIdentityInterface
{
    /**
     * @param list<string>         $roles
     * @param array<string, mixed> $claims
     */
    public function __construct(
        public string $subject = 'user-1',
        public ?string $email = null,
        public array $roles = [],
        public array $claims = [],
    ) {}
}
