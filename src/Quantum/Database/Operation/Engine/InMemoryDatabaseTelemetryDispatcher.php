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
    private readonly DatabaseTelemetryDispatchPreparation $preparation;
    private readonly DatabaseTelemetrySignalAlertSampler $alertSampler;

    public function __construct(
        private readonly int $maxReports = 256,
        ?DatabaseTelemetrySignalMapper $mapper = null,
        ?DatabaseTelemetrySignalAlertSampler $alertSampler = null,
    ) {
        $this->exporter = new InMemoryTelemetryExporter($maxReports);
        $resolvedMapper = $mapper ?? new DatabaseTelemetrySignalMapper();
        $this->alertSampler = $alertSampler ?? new DatabaseTelemetrySignalAlertSampler();
        $this->preparation = new DatabaseTelemetryDispatchPreparation($resolvedMapper, $this->alertSampler);
    }

    public function dispatch(DatabaseTelemetryReport $report): DatabaseTelemetryReport
    {
        $prepared = $this->preparation->prepare($report);
        $this->reports[] = $prepared['report'];
        $this->exporter->export($prepared['signal']);

        if (count($this->reports) <= $this->maxReports) {
            return $prepared['report'];
        }

        $excess = count($this->reports) - $this->maxReports;
        if ($excess > 0) {
            $this->reports = array_slice($this->reports, $excess);
        }

        return $prepared['report'];
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
