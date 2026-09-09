<?php

declare(strict_types=1);

namespace Quantum\Auth\Contracts;

use Quantum\Auth\Identity\IdentityInterface;
use Quantum\Auth\Sessions\AuthenticationSession;
use Quantum\Auth\Sessions\AuthenticationSessionRecoveryReason;

interface AuthenticationSessionRepositoryInterface
{
    public function save(AuthenticationSession $session): void;

    public function find(string $sessionId): ?AuthenticationSession;

    /**
     * @return list<AuthenticationSession>
     */
    public function listForIdentity(IdentityInterface $identity): array;

    public function delete(string $sessionId, AuthenticationSessionRecoveryReason $reason = AuthenticationSessionRecoveryReason::Revoked): void;

    public function deleteForIdentity(
        IdentityInterface $identity,
        ?string $exceptSessionId = null,
        AuthenticationSessionRecoveryReason $reason = AuthenticationSessionRecoveryReason::Revoked,
    ): void;

    public function findRecoveryReason(string $sessionId): ?AuthenticationSessionRecoveryReason;

    public function touch(AuthenticationSession $session): void;

    public function purgeExpired(?int $now = null): int;
}
