<?php

declare(strict_types=1);

namespace Quantum\Auth\Recovery;

enum RecoveryNotificationType: string
{
    case PasswordResetRequested = 'password_reset_requested';
    case PasswordResetCompleted = 'password_reset_completed';
}
