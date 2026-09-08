<?php

declare(strict_types=1);

namespace Quantum\Auth\Contracts;

use Quantum\Auth\Identity\IdentityInterface;

interface MultiFactorIdentityProviderInterface
{
    public function supportsSecondFactor(IdentityInterface $identity): bool;

    public function requiresSecondFactor(IdentityInterface $identity): bool;

    public function verifySecondFactor(IdentityInterface $identity, string $secondFactor): bool;
}
