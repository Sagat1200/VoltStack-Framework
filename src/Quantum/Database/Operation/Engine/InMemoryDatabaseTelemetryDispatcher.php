<?php

declare(strict_types=1);

namespace Quantum\Database\Operation\Engine;

use Quantum\Database\Operation\Contracts\DatabaseTelemetryDispatcherInterface;
use Quantum\Database\Operation\DatabaseTelemetryReport;

final class InMemoryDatabaseTelemetryDispatcher implements DatabaseTelemetryDispatcherInterface
{
    /**
     * @var list<DatabaseTelemetryReport>
     */
    private array $reports = [];

    public function __construct(
        private readonly int $maxReports = 256,
    ) {
    }

    public function dispatch(DatabaseTelemetryReport $report): void
    {
        $this->reports[] = $report;

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
    }
}
