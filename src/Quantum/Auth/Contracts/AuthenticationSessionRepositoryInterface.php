<?php

declare(strict_types=1);

namespace Quantum\Auth\Contracts;

use Quantum\Auth\Identity\IdentityInterface;
use Quantum\Auth\Sessions\AuthenticationSession;

interface AuthenticationSessionRepositoryInterface
{
    public function save(AuthenticationSession $session): void;

    public function find(string $sessionId): ?AuthenticationSession;

    public function delete(string $sessionId): void;

    public function deleteForIdentity(IdentityInterface $identity, ?string $exceptSessionId = null): void;

    public function purgeExpired(?int $now = null): int;
}
