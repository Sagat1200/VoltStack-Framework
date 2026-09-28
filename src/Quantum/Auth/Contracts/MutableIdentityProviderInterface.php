<?php

declare(strict_types=1);

namespace Quantum\Auth\Contracts;

use Quantum\Auth\Identity\IdentityInterface;
use Quantum\Auth\Identity\IdentitySecurityState;

interface MutableIdentityProviderInterface
{
    public function updateSecurityState(IdentityInterface $identity, IdentitySecurityState $state, ?string $reason = null): bool;

    public function setSecondFactorRequired(IdentityInterface $identity, bool $required): bool;

    public function recordFailedAuthentication(IdentityInterface $identity): void;

    public function clearFailedAuthentication(IdentityInterface $identity): void;

    public function isLockedOut(IdentityInterface $identity): bool;

    public function unlock(IdentityInterface $identity): bool;
}
