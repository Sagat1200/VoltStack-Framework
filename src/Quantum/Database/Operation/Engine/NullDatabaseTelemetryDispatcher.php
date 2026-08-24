<?php

declare(strict_types=1);

namespace Quantum\Database\Operation\Engine;

use Quantum\Database\Operation\Contracts\DatabaseTelemetryDispatcherInterface;
use Quantum\Database\Operation\DatabaseTelemetryReport;

final class NullDatabaseTelemetryDispatcher implements DatabaseTelemetryDispatcherInterface
{
    public function dispatch(DatabaseTelemetryReport $report): void
    {
    }
}
