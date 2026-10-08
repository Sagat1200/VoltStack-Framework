<?php

declare(strict_types=1);

namespace Quantum\Auth\Recovery;

enum RecoveryPurpose: string
{
    case PasswordReset = 'password_reset';
}
