<?php

declare(strict_types=1);

namespace Quantum\Database\Operation\Engine;

use Quantum\Database\Operation\Contracts\DatabaseTelemetryDispatcherInterface;
use Quantum\Database\Operation\DatabaseTelemetryReport;
use Quantum\Telemetry\Engine\NullTelemetryExporter;

final class NullDatabaseTelemetryDispatcher implements DatabaseTelemetryDispatcherInterface
{
    private readonly NullTelemetryExporter $exporter;
    private readonly DatabaseTelemetrySignalMapper $mapper;

    public function __construct()
    {
        $this->exporter = new NullTelemetryExporter();
        $this->mapper = new DatabaseTelemetrySignalMapper();
    }

    public function dispatch(DatabaseTelemetryReport $report): void
    {
        $this->exporter->export($this->mapper->map($report));
    }
}
