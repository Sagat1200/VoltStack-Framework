<?php

declare(strict_types=1);

namespace Quantum\Database\Execution;

use PDOException;
use RuntimeException;
use Throwable;

final class ExecutionException extends RuntimeException
{
    public function __construct(
        private readonly ExecutionFailure $failure,
        ?Throwable $previous = null,
    ) {
        parent::__construct($failure->message, 0, $previous);
    }

    public function failure(): ExecutionFailure
    {
        return $this->failure;
    }

    public static function fromThrowable(
        string $phase,
        Throwable $throwable,
        CompiledDatabaseCommand $command,
        ?ExecutionContext $context = null,
    ): self {
        $sqlState = null;
        $nativeCode = null;

        if ($throwable instanceof PDOException) {
            $sqlState = is_array($throwable->errorInfo ?? null) ? ($throwable->errorInfo[0] ?? null) : null;
            $nativeCode = is_array($throwable->errorInfo ?? null) ? ($throwable->errorInfo[1] ?? $throwable->getCode()) : $throwable->getCode();
        } else {
            $nativeCode = $throwable->getCode();
        }

        $failure = new ExecutionFailure(
            phase: $phase,
            category: 'statement_execution',
            message: sprintf('Database execution failed during [%s].', $phase),
            sqlState: is_string($sqlState) ? $sqlState : null,
            nativeCode: $nativeCode,
            connectionReusable: true,
            retryable: false,
            diagnostics: [
                'sql' => $command->sql,
                'connection' => $command->connectionName,
                'correlation_id' => $context?->correlationId,
                'native_message' => $throwable->getMessage(),
            ],
        );

        return new self($failure, $throwable);
    }
}
