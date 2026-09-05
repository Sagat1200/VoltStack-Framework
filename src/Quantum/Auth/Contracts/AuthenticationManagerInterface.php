<?php

declare(strict_types=1);

namespace Quantum\Auth\Contracts;

use Quantum\Auth\Context\AuthenticationContext;

interface AuthenticationManagerInterface
{
    public function user(): mixed;

    public function setUser(mixed $user): void;

    public function check(): bool;

    public function guest(): bool;

    public function id(): mixed;

    public function context(): ?AuthenticationContext;

    public function logout(): void;
}