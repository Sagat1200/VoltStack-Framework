<?php

declare(strict_types=1);

namespace Quantum\Database\Operation\Contracts;

use Quantum\Database\Operation\DatabaseTelemetryReport;

interface DatabaseTelemetryDispatcherInterface
{
    public function dispatch(DatabaseTelemetryReport $report): void;
}