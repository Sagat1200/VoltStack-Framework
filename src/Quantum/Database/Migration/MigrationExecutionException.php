<?php

declare(strict_types=1);

namespace Quantum\Database\Migration;

final class MigrationExecutionException extends \RuntimeException
{
    public function __construct(
        public readonly MigrationOperationalFailure $failure,
        public readonly MigrationExecutionCheckpoint $checkpoint,
        public readonly bool $retryable = false,
        string $message = '',
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, (int) ($previous?->getCode() ?? 0), $previous);
    }
}
