<?php

declare(strict_types=1);

namespace Quantum\Auth\Decisions;

enum AuthenticationDecisionStatus: string
{
    case Authenticated = 'authenticated';
    case Rejected = 'rejected';
    case Unauthenticated = 'unauthenticated';
}
