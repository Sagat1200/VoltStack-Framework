<?php

declare(strict_types=1);

namespace Quantum\Database\Execution;

use PDOException;
use PDOStatement;
use Quantum\Database\Contracts\ConnectionManagerInterface;
use Quantum\Database\Contracts\StatementExecutorInterface;
use Quantum\Database\Telemetry\DatabaseTelemetryEmitter;

final class StatementExecutor implements StatementExecutorInterface
{
    public function __construct(
        private readonly ConnectionManagerInterface $connections,
        private readonly DatabaseTelemetryEmitter $telemetry,
    ) {
    }

    public function execute(
        CompiledDatabaseCommand $command,
        RuntimeBindingSet $bindings = new RuntimeBindingSet(),
        ?ExecutionContext $context = null,
    ): DatabaseResult {
        $connection = $this->connections->connection($command->connectionName);
        $startedAt = hrtime(true);

        try {
            $statement = $connection->pdo()->prepare($command->sql);
            $statement->execute($bindings->normalized());

            $result = $this->mapResult($statement, $connection->pdo()->lastInsertId(), $command);
            $this->telemetry->queryExecuted($command, $result, (hrtime(true) - $startedAt) / 1_000_000);

            return $result;
        } catch (PDOException $exception) {
            $this->telemetry->queryFailed($command, $exception, (hrtime(true) - $startedAt) / 1_000_000);
            throw ExecutionException::fromThrowable('statement_execution', $exception, $command, $context);
        }
    }

    private function mapResult(PDOStatement $statement, int|string|false $lastInsertId, CompiledDatabaseCommand $command): DatabaseResult
    {
        $rows = [];

        if ($command->resultType === DatabaseResultType::Rows || $command->resultType === DatabaseResultType::Scalar) {
            /** @var list<array<string, mixed>> $rows */
            $rows = $statement->fetchAll($command->fetchMode);
        }

        $type = $command->resultType;

        if ($type === DatabaseResultType::Rows && $rows === [] && $statement->columnCount() === 0) {
            $type = DatabaseResultType::NoResult;
        }

        if ($type === DatabaseResultType::Scalar && $rows === [] && $statement->columnCount() === 0) {
            $type = DatabaseResultType::NoResult;
        }

        return new DatabaseResult(
            type: $type,
            rows: $rows,
            affectedRows: $statement->rowCount(),
            lastInsertId: $lastInsertId !== false && $lastInsertId !== '0' ? $lastInsertId : null,
            metadata: [
                'column_count' => $statement->columnCount(),
            ],
        );
    }
}
