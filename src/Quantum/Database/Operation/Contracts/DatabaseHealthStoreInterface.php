<?php

declare(strict_types=1);

namespace Quantum\Database\Operation\Contracts;

use Quantum\Database\Operation\DatabaseTelemetryReport;

interface DatabaseHealthStoreInterface
{
    public function persist(DatabaseTelemetryReport $report): void;

    public function latest(): ?DatabaseTelemetryReport;

    /**
     * @return list<DatabaseTelemetryReport>
     */
    public function recent(int $limit = 10): array;

    /**
     * @return array<string, mixed>
     */
    public function aggregate(int $limit = 50): array;
}
