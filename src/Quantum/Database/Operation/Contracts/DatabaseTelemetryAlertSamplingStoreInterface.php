<?php

declare(strict_types=1);

namespace Quantum\Database\Operation\Contracts;

interface DatabaseTelemetryAlertSamplingStoreInterface
{
    public function nextOccurrence(string $nodeId, string $alertName): int;

    public function reset(?string $nodeId = null): void;
}