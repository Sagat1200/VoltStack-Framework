<?php

declare(strict_types=1);

namespace Quantum\Database\Operation;

enum DatabaseOperationalFailure: string
{
    case InvalidPlan = 'invalid_plan';
    case Unauthorized = 'unauthorized';
    case Degraded = 'degraded';
    case Duplicate = 'duplicate';
    case ResourceExhausted = 'resource_exhausted';
    case Transient = 'transient';
    case Permanent = 'permanent';
    case VerificationFailed = 'verification_failed';
}
