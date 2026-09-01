<?php

declare(strict_types=1);

namespace Quantum\Database\Operation\Engine;

use Quantum\Database\Operation\Contracts\DatabaseTelemetryDispatcherInterface;
use Quantum\Database\Operation\DatabaseTelemetryReport;
use Quantum\Telemetry\Engine\JsonLineTelemetryExporter;

final class JsonLineDatabaseTelemetryDispatcher implements DatabaseTelemetryDispatcherInterface
{
    private readonly JsonLineTelemetryExporter $exporter;
    private readonly DatabaseTelemetryDispatchPreparation $preparation;

    public function __construct(
        private readonly string $filePath,
        private readonly int $maxBytesPerLine = 32768,
        ?DatabaseTelemetrySignalMapper $mapper = null,
        ?DatabaseTelemetrySignalAlertSampler $alertSampler = null,
    ) {
        $this->exporter = new JsonLineTelemetryExporter($filePath, $maxBytesPerLine);
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

    public function filePath(): string
    {
        return $this->filePath;
    }
}
