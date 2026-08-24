<?php

declare(strict_types=1);

namespace Quantum\Database\Operation\Engine;

use Quantum\Database\Operation\DatabaseHealthAggregation;
use Quantum\Database\Operation\Contracts\DatabaseHealthStoreInterface;
use Quantum\Database\Operation\DatabaseTelemetryReport;

final class NullDatabaseHealthStore implements DatabaseHealthStoreInterface
{
    public function persist(DatabaseTelemetryReport $report): void {}

    public function latest(): ?DatabaseTelemetryReport
    {
        return null;
    }

    public function recent(int $limit = 10): array
    {
        return [];
    }

    public function aggregate(int $limit = 50): array
    {
        return DatabaseHealthAggregation::aggregate([]);
    }
}
