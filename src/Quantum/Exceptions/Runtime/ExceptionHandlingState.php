<?php

declare(strict_types=1);

namespace Quantum\Exceptions\Runtime;

use Quantum\Exceptions\Enums\ExceptionHandlingStatus;

final class ExceptionHandlingState
{
    public ExceptionHandlingStatus $status = ExceptionHandlingStatus::Pending;
    public int $attempts = 0;
}

