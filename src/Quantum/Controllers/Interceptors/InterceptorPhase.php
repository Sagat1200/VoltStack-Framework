<?php

declare(strict_types=1);

namespace Quantum\Controllers\Interceptors;

enum InterceptorPhase: string
{
    case Around = 'around';
}

