<?php

declare(strict_types=1);

namespace Quantum\Exceptions\Bridges\Runtime;

use Quantum\Exceptions\Context\ExceptionContext;
use Quantum\Exceptions\Runtime\ExceptionScope;
use VoltStack\Runtime\Reset\ResetReport;

interface ExceptionRuntimeBridge
{
    public function begin(RuntimeOperation $operation): ExceptionScope;

    public function context(ExceptionScope $scope): ExceptionContext;

    public function finalize(ExceptionScope $scope, FinalizationOutcome $outcome): void;

    public function close(ExceptionScope $scope): ResetReport;
}
