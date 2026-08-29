<?php

declare(strict_types=1);

namespace Quantum\Database\Operation\Engine;

use Quantum\Database\Operation\Contracts\DatabaseTelemetryDispatcherInterface;
use Quantum\Database\Operation\DatabaseTelemetryReport;
use Quantum\Telemetry\Engine\JsonLineTelemetryExporter;

final class JsonLineDatabaseTelemetryDispatcher implements DatabaseTelemetryDispatcherInterface
{
    private readonly JsonLineTelemetryExporter $exporter;
    private readonly DatabaseTelemetrySignalMapper $mapper;

    public function __construct(
        private readonly string $filePath,
        private readonly int $maxBytesPerLine = 32768,
    ) {
        $this->exporter = new JsonLineTelemetryExporter($filePath, $maxBytesPerLine);
        $this->mapper = new DatabaseTelemetrySignalMapper();
    }

    public function dispatch(DatabaseTelemetryReport $report): void
    {
        $this->exporter->export($this->mapper->map($report));
    }

    public function filePath(): string
    {
        return $this->filePath;
    }
}
