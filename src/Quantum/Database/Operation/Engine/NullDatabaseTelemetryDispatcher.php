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
    private readonly DatabaseTelemetrySignalAlertSampler $alertSampler;

    public function __construct(
        ?DatabaseTelemetrySignalMapper $mapper = null,
        ?DatabaseTelemetrySignalAlertSampler $alertSampler = null,
    ) {
        $this->exporter = new NullTelemetryExporter();
        $this->mapper = $mapper ?? new DatabaseTelemetrySignalMapper();
        $this->alertSampler = $alertSampler ?? new DatabaseTelemetrySignalAlertSampler();
    }

    public function dispatch(DatabaseTelemetryReport $report): void
    {
        $this->exporter->export($this->alertSampler->apply($this->mapper->map($report)));
    }
}
