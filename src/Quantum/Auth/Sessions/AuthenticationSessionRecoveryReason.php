<?php

declare(strict_types=1);

namespace Quantum\Auth\Sessions;

enum AuthenticationSessionRecoveryReason: string
{
    case Revoked = 'session_revoked';
    case Expired = 'session_expired';
}