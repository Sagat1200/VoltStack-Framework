<?php

declare(strict_types=1);

namespace Quantum\Database\Operation;

final class DatabaseOperationException extends \RuntimeException
{
    public function __construct(
        public readonly DatabaseOperationalFailure $failure,
        public readonly DatabaseDiagnosticSnapshot $snapshot,
        public readonly ?DatabaseOperationPlan $plan = null,
        string $message = '',
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, (int) ($previous?->getCode() ?? 0), $previous);
    }
}
