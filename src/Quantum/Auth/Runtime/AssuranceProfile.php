<?php

declare(strict_types=1);

namespace Quantum\Auth\Runtime;

enum AssuranceProfile: int
{
    case Lowest = 0;
    case LowBasic = 1;
    case LowPassword = 2;
    case MediumToken = 3;
    case MediumSessionRemembered = 4;
    case HighMfa = 5;
    case HighHardwareBacked = 6;
    case HighestBiometricPasskey = 7;

    public function displayName(): string
    {
        return match ($this) {
            self::Lowest => 'lowest',
            self::LowBasic => 'low_basic',
            self::LowPassword => 'low_password',
            self::MediumToken => 'medium_token',
            self::MediumSessionRemembered => 'medium_session_remembered',
            self::HighMfa => 'high_mfa',
            self::HighHardwareBacked => 'high_hardware_backed',
            self::HighestBiometricPasskey => 'highest_biometric_passkey',
        };
    }
}
