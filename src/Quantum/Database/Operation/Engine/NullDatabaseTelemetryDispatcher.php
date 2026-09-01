<?php

declare(strict_types=1);

namespace Quantum\Database\Operation\Engine;

use Quantum\Database\Operation\Contracts\DatabaseTelemetryDispatcherInterface;
use Quantum\Database\Operation\DatabaseTelemetryReport;
use Quantum\Telemetry\Engine\NullTelemetryExporter;

final class NullDatabaseTelemetryDispatcher implements DatabaseTelemetryDispatcherInterface
{
    private readonly NullTelemetryExporter $exporter;
    private readonly DatabaseTelemetryDispatchPreparation $preparation;

    public function __construct(
        ?DatabaseTelemetrySignalMapper $mapper = null,
        ?DatabaseTelemetrySignalAlertSampler $alertSampler = null,
    ) {
        $this->exporter = new NullTelemetryExporter();
        $this->preparation = new DatabaseTelemetryDispatchPreparation(
            $mapper ?? new DatabaseTelemetrySignalMapper(),
            $alertSampler ?? new DatabaseTelemetrySignalAlertSampler(),
        );
    }

    public function dispatch(DatabaseTelemetryReport $report): DatabaseTelemetryReport
    {
        $prepared = $this->preparation->prepare($report);
        $this->exporter->export($prepared['signal']);

        return $prepared['report'];
    }
}
