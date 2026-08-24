<?php

declare(strict_types=1);

namespace Quantum\Database\Operation;

use Quantum\Database\Trace\DatabaseDeadline;

final readonly class DatabaseOperationPlan
{
    public function __construct(
        public RawOperation $operation,
        public string $connectionName,
        public string $driver,
        public string $logicalTarget,
        public string $circuitSegment,
        public string $fingerprint,
        public string $sqlFingerprint,
        public string $safeSqlPreview,
        public int $maxRows,
        public int $maxDepth,
        public int $detectedDepth,
        public int $retryLimit,
        public bool $retryable,
        public DatabaseDeadline $deadline,
        public DatabaseExecutionPolicy $policy,
    ) {}
}
