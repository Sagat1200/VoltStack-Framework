<?php

declare(strict_types=1);

namespace Quantum\Controllers\Security\Decision;

enum SecurityDecisionEffect: string
{
    case Allow = 'allow';
    case Deny = 'deny';
    case Abstain = 'abstain';
    case Challenge = 'challenge';
}
