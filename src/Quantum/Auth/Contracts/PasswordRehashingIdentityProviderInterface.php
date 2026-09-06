<?php

declare(strict_types=1);

namespace Quantum\Auth\Contracts;

use Quantum\Auth\Identity\IdentityInterface;

interface PasswordRehashingIdentityProviderInterface
{
    public function upgradePasswordHash(IdentityInterface $identity, string $passwordHash): bool;
}
