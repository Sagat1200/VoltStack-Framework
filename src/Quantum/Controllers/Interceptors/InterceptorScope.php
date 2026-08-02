<?php

declare(strict_types=1);

namespace Quantum\Controllers\Interceptors;

enum InterceptorScope: string
{
    case Execution = 'execution';
    case Singleton = 'singleton';
}

