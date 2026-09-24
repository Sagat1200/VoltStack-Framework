<?php

declare(strict_types=1);

namespace Quantum\Authorization\Decision;

enum Decision: string
{
    case Allow = 'allow';
    case Deny = 'deny';
    case Abstain = 'abstain';
    case Challenge = 'challenge';
    case Failure = 'failure';
}
