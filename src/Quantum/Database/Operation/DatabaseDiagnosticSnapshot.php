<?php

declare(strict_types=1);

namespace Quantum\Database\Operation;

final readonly class DatabaseDiagnosticSnapshot
{
    /**
     * @param list<DatabaseDiagnosticEvent> $events
     */
    public function __construct(
        public string $fingerprint,
        public string $connectionName,
        public string $driver,
        public int $attempts,
        public int $durationMs,
        public int $rowsRead,
        public int $affectedRows,
        public bool $slowQuery,
        public string $outcome,
        public ?DatabaseOperationalFailure $failure,
        public bool $retryable,
        public string $circuitState,
        public int $deadlineRemainingMs,
        public array $events,
    ) {}
}
