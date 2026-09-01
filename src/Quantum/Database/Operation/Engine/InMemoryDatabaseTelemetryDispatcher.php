<?php

declare(strict_types=1);

namespace Quantum\Database\Operation\Engine;

use Quantum\Database\Operation\Contracts\DatabaseTelemetryDispatcherInterface;
use Quantum\Database\Operation\DatabaseTelemetryReport;
use Quantum\Telemetry\Engine\InMemoryTelemetryExporter;
use Quantum\Telemetry\TelemetrySignal;

final class InMemoryDatabaseTelemetryDispatcher implements DatabaseTelemetryDispatcherInterface
{
    /**
     * @var list<DatabaseTelemetryReport>
     */
    private array $reports = [];
    private readonly InMemoryTelemetryExporter $exporter;
    private readonly DatabaseTelemetrySignalMapper $mapper;
    private readonly DatabaseTelemetrySignalAlertSampler $alertSampler;

    public function __construct(
        private readonly int $maxReports = 256,
        ?DatabaseTelemetrySignalMapper $mapper = null,
        ?DatabaseTelemetrySignalAlertSampler $alertSampler = null,
    ) {
        $this->exporter = new InMemoryTelemetryExporter($maxReports);
        $this->mapper = $mapper ?? new DatabaseTelemetrySignalMapper();
        $this->alertSampler = $alertSampler ?? new DatabaseTelemetrySignalAlertSampler();
    }

    public function dispatch(DatabaseTelemetryReport $report): void
    {
        $this->reports[] = $report;
        $this->exporter->export($this->alertSampler->apply($this->mapper->map($report)));

        if (count($this->reports) <= $this->maxReports) {
            return;
        }

        $excess = count($this->reports) - $this->maxReports;
        if ($excess > 0) {
            $this->reports = array_slice($this->reports, $excess);
        }
    }

    /**
     * @return list<DatabaseTelemetryReport>
     */
    public function reports(): array
    {
        return $this->reports;
    }

    public function last(): ?DatabaseTelemetryReport
    {
        $last = $this->reports[array_key_last($this->reports)] ?? null;

        return $last instanceof DatabaseTelemetryReport ? $last : null;
    }

    public function clear(): void
    {
        $this->reports = [];
        $this->exporter->clear();
        $this->alertSampler->reset();
    }

    /**
     * @return list<TelemetrySignal>
     */
    public function signals(): array
    {
        return $this->exporter->signals();
    }
}
