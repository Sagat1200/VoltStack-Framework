<?php

declare(strict_types=1);

namespace Quantum\Exceptions\Enums;

enum WorkerDisposition: string
{
    case Reuse = 'reuse';
    case Reset = 'reset';
    case Terminate = 'terminate';
}

