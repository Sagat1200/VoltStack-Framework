<?php

declare(strict_types=1);

namespace Quantum\Controllers\Runtime;

enum ControllerExecutionState: string
{
    case Created = 'created';
    case Running = 'running';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
}