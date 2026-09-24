<?php

declare(strict_types=1);

namespace Quantum\Database\Telemetry;

use Quantum\Database\Config\DatabaseConfiguration;
use Quantum\Database\Execution\CompiledDatabaseCommand;
use Quantum\Database\Execution\DatabaseResult;
use Quantum\Database\Transaction\TransactionContext;
use Quantum\Telemetry\Contracts\TelemetryManagerInterface;
use Quantum\Telemetry\TelemetrySignal;
use Throwable;
use VoltStack\Runtime\Context\RuntimeContext;

final class DatabaseTelemetryEmitter
{
    public function __construct(
        private readonly TelemetryManagerInterface $telemetry,
        private readonly DatabaseConfiguration $configuration,
    ) {
    }

    public function queryExecuted(CompiledDatabaseCommand $command, DatabaseResult $result, float $durationMs): void
    {
        $this->emit(
            'database.query.executed',
            [
                'connection' => $command->connectionName ?? $this->configuration->defaultConnectionName,
                'result_type' => strtolower($command->resultType->name),
                'statement_type' => $this->statementType($command->sql),
                'status' => 'success',
            ],
            [
                'duration_ms' => round($durationMs, 3),
                'query_fingerprint' => $this->fingerprint($command->sql),
                'affected_rows' => $result->affectedRows,
                'row_count' => count($result->rows()),
                'last_insert_id' => $result->lastInsertId,
            ],
        );
    }

    public function queryFailed(CompiledDatabaseCommand $command, Throwable $throwable, float $durationMs): void
    {
        $this->emit(
            'database.query.failed',
            [
                'connection' => $command->connectionName ?? $this->configuration->defaultConnectionName,
                'result_type' => strtolower($command->resultType->name),
                'statement_type' => $this->statementType($command->sql),
                'status' => 'failure',
                'exception' => $throwable::class,
            ],
            [
                'duration_ms' => round($durationMs, 3),
                'query_fingerprint' => $this->fingerprint($command->sql),
                'message_fingerprint' => sha1($throwable->getMessage()),
            ],
        );
    }

    public function transactionBegan(TransactionContext $context): void
    {
        $this->emit(
            'database.transaction.began',
            [
                'connection' => $context->connectionName(),
                'state' => strtolower($context->state()->name),
            ],
            [
                'transaction_id' => $context->id()->value,
                'depth' => $context->depth(),
            ],
        );
    }

    public function transactionCommitted(TransactionContext $context): void
    {
        $this->emit(
            'database.transaction.committed',
            [
                'connection' => $context->connectionName(),
                'state' => strtolower($context->state()->name),
            ],
            [
                'transaction_id' => $context->id()->value,
                'depth' => $context->depth(),
            ],
        );
    }

    public function transactionRolledBack(TransactionContext $context): void
    {
        $this->emit(
            'database.transaction.rolled_back',
            [
                'connection' => $context->connectionName(),
                'state' => strtolower($context->state()->name),
            ],
            [
                'transaction_id' => $context->id()->value,
                'depth' => $context->depth(),
            ],
        );
    }

    public function transactionMarkedRollbackOnly(TransactionContext $context): void
    {
        $this->emit(
            'database.transaction.rollback_only',
            [
                'connection' => $context->connectionName(),
                'state' => strtolower($context->state()->name),
            ],
            [
                'transaction_id' => $context->id()->value,
                'depth' => $context->depth(),
            ],
        );
    }

    public function migrationsCompleted(string $operation, int $count, ?string $path, ?string $connectionName): void
    {
        $this->emit(
            'database.migration.' . $operation,
            [
                'connection' => $connectionName ?? $this->configuration->defaultConnectionName,
                'operation' => $operation,
            ],
            [
                'count' => $count,
                'path' => $path,
            ],
        );
    }

    private function emit(string $name, array $attributes, array $payload): void
    {
        if (! (bool) $this->configuration->telemetryOption('enabled', true)) {
            return;
        }

        $context = RuntimeContext::current();

        try {
            $this->telemetry->emit(new TelemetrySignal(
                name: $name,
                type: 'event',
                source: 'quantum.database',
                occurredAt: gmdate('c'),
                payload: $payload,
                attributes: $attributes,
                requestId: $context?->requestId(),
            ));
        } catch (Throwable) {
        }
    }

    private function statementType(string $sql): string
    {
        $statement = strtoupper((string) preg_replace('/\s+/', ' ', trim($sql)));
        $first = strtok($statement, ' ');

        return is_string($first) && $first !== ''
            ? strtolower($first)
            : 'unknown';
    }

    private function fingerprint(string $sql): string
    {
        $normalized = preg_replace('/\'(?:[^\']|\'\')*\'/', '?', $sql);
        $normalized = preg_replace('/"(?:""|[^"])*"/', '?', (string) $normalized);
        $normalized = preg_replace('/\b\d+\b/', '?', (string) $normalized);
        $normalized = preg_replace('/\s+/', ' ', trim((string) $normalized));

        return 'qf_' . substr(sha1((string) $normalized), 0, 16);
    }
}
