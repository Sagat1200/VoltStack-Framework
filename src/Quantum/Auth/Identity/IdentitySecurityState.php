<?php

declare(strict_types=1);

namespace Quantum\Auth\Identity;

enum IdentitySecurityState: string
{
    case Active = 'active';
    case Disabled = 'disabled';
    case Suspended = 'suspended';
    case Locked = 'locked';

    public function isEligibleForAuthentication(): bool
    {
        return $this === self::Active;
    }
}
