<?php

declare(strict_types=1);

namespace Quantum\Auth\Runtime;

enum PolicyEffect: string
{
    case Allow = 'allow';
    case Deny = 'deny';
}
