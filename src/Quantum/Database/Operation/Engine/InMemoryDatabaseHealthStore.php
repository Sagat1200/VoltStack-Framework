<?php

declare(strict_types=1);

namespace Quantum\Database\Operation\Engine;

use Quantum\Database\Operation\DatabaseHealthAggregation;
use Quantum\Database\Operation\Contracts\DatabaseHealthStoreInterface;
use Quantum\Database\Operation\DatabaseTelemetryReport;

final class InMemoryDatabaseHealthStore implements DatabaseHealthStoreInterface
{
    /**
     * @var list<DatabaseTelemetryReport>
     */
    private array $reports = [];

    public function __construct(
        private readonly int $maxReports = 256,
    ) {}

    public function persist(DatabaseTelemetryReport $report): void
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

    public function latest(): ?DatabaseTelemetryReport
    {
        $last = $this->reports[array_key_last($this->reports)] ?? null;

        return $last instanceof DatabaseTelemetryReport ? $last : null;
    }

    public function recent(int $limit = 10): array
    {
        return array_values(array_slice($this->reports, -max(1, $limit)));
    }

    public function aggregate(int $limit = 50): array
    {
        return DatabaseHealthAggregation::aggregate($this->recent($limit));
    }
}
