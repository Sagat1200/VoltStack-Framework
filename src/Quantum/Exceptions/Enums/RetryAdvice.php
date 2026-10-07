<?php

declare(strict_types=1);

namespace Quantum\Exceptions\Enums;

enum RetryAdvice: string
{
    case Never = 'never';
    case Conditional = 'conditional';
    case ReconcileFirst = 'reconcile_first';
}
