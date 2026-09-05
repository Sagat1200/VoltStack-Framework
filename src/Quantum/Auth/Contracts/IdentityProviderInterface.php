<?php

declare(strict_types=1);

namespace Quantum\Auth\Contracts;

use Quantum\Auth\Identity\IdentityInterface;

interface IdentityProviderInterface
{
    public function findByIdentifier(string $identifier): ?IdentityInterface;

    public function passwordHashFor(IdentityInterface $identity): ?string;
}