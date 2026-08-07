<?php

declare(strict_types=1);

namespace Quantum\Controllers\Security\Context;

enum AuthenticationStrength: int
{
    case Anonymous = 0;
    case Password = 10;
    case Token = 20;
    case MultiFactor = 30;
    case HardwareBacked = 40;
}
