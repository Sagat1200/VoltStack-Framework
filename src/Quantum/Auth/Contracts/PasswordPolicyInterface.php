<?php

declare(strict_types=1);

namespace Quantum\Auth\Contracts;

interface PasswordPolicyInterface
{
    public function accepts(string $plainPassword): bool;

    public function verify(string $plainPassword, string $passwordHash): bool;

    public function hash(string $plainPassword): string;

    public function needsRehash(string $passwordHash): bool;
}
