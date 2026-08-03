<?php

declare(strict_types=1);

namespace Quantum\Controllers\Runtime;

enum ControllerShortCircuitOrigin: string
{
    case Interceptor = 'interceptor';
}

