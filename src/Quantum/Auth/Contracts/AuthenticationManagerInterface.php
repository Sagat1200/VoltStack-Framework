<?php

declare(strict_types=1);

namespace Quantum\Auth\Contracts;

use Quantum\Auth\Context\AuthenticationContext;

interface AuthenticationManagerInterface
{
    /**
     * @param array<string, mixed> $credentials
     */
    public function attempt(array $credentials): bool;

    public function login(mixed $user): void;

    public function user(): mixed;

    public function setUser(mixed $user): void;

    public function check(): bool;

    public function guest(): bool;

    public function id(): mixed;

    public function context(): ?AuthenticationContext;

    public function logout(): void;
}
