<?php

declare(strict_types=1);

namespace Quantum\Exceptions\Enums;

enum RecoveryAction: string
{
    case None = 'none';
    case RetryAdvice = 'retry_advice';
    case FallbackAdvice = 'fallback_advice';
    case Reconcile = 'reconcile';
    case Abort = 'abort';
}
