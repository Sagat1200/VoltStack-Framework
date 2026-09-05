<?php

declare(strict_types=1);

namespace Quantum\Auth\Contracts;

use Quantum\Auth\Context\AuthenticationContext;
use Quantum\Auth\Exceptions\AuthenticationException;

interface AuthenticationManagerInterface
{
    /**
     * @param array<string, mixed> $credentials
     */
    public function attempt(array $credentials): bool;

    /**
     * @param array<string, mixed> $credentials
     *
     * @throws AuthenticationException
     */
    public function attemptOrFail(array $credentials): void;

    public function login(mixed $user): void;

    public function user(): mixed;

    public function setUser(mixed $user): void;

    public function check(): bool;

    public function guest(): bool;

    public function id(): mixed;

    public function context(): ?AuthenticationContext;

    public function logout(): void;
}
