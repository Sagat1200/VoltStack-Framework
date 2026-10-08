<?php

declare(strict_types=1);

namespace Quantum\Auth\Contracts;

use Quantum\Auth\Identity\IdentityInterface;

interface MultiFactorIdentityProviderInterface
{
    public function supportsSecondFactor(IdentityInterface $identity): bool;

    public function requiresSecondFactor(IdentityInterface $identity): bool;

    /**
     * @return list<string>
     */
    public function availableSecondFactorMethods(IdentityInterface $identity): array;

    public function verifySecondFactor(IdentityInterface $identity, string $secondFactor, ?string $method = null): bool;
}
