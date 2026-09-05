<?php

declare(strict_types=1);

namespace Quantum\Auth\Contracts;

use Quantum\Auth\Sessions\AuthenticationSession;

interface AuthenticationSessionRepositoryInterface
{
    public function save(AuthenticationSession $session): void;

    public function find(string $sessionId): ?AuthenticationSession;

    public function delete(string $sessionId): void;
}
