<?php

declare(strict_types=1);

namespace Quantum\Exceptions\Enums;

enum HandlingResultKind: string
{
    case Rendered = 'rendered';
    case Propagate = 'propagate';
    case JobDecision = 'job_decision';
    case AbortTransport = 'abort_transport';
    case Emergency = 'emergency';
}
