<?php

declare(strict_types=1);

namespace Quantum\Database\Migration;

enum MigrationOperationalFailure: string
{
    case InvalidPlan = 'invalid_plan';
    case Unauthorized = 'unauthorized';
    case ResourceExhausted = 'resource_exhausted';
    case Transient = 'transient';
    case Permanent = 'permanent';
    case VerificationFailed = 'verification_failed';
}