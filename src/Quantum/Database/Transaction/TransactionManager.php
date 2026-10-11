<?php

declare(strict_types=1);

namespace Quantum\Database\Transaction;

use PDO;
use Quantum\Database\Contracts\ConnectionManagerInterface;
use Quantum\Database\Contracts\TransactionManagerInterface;
use Quantum\Database\Runtime\DatabaseExecutionScope;
use Quantum\Database\Telemetry\DatabaseTelemetryEmitter;
use Quantum\Database\Transaction\Exception\IsolationNotSupportedException;
use Quantum\Database\Transaction\Exception\NestedTransactionNotSupportedException;
use Quantum\Database\Transaction\Exception\PropagationNotSupportedException;
use Quantum\Database\Transaction\Exception\SavepointNotSupportedException;
use Quantum\Database\Transaction\Exception\TransactionAlreadyActiveException;
use Quantum\Database\Transaction\Exception\TransactionCommitException;
use Quantum\Database\Transaction\Exception\TransactionNotActiveException;
use Quantum\Database\Transaction\Exception\TransactionOutcomeUnknownException;
use Quantum\Database\Transaction\Exception\TransactionRollbackException;
use Quantum\Database\Transaction\Exception\TransactionRollbackOnlyException;
use Throwable;

/**
 * Orchestrates the physical transaction lifecycle on behalf of one or more
 * connections.
 *
 * Invariants:
 *  - TransactionManager holds NO mutable current-transaction state. All live
 *    transaction state lives in scoped TransactionContext instances.
 *  - flush() !== commit(): this manager does not flush the ORM.
 *  - Unknown outcome is terminal and is never silently converted to rollback.
 *  - REQUIRES_NEW is not supported in this iteration.
 */
final class TransactionManager implements TransactionManagerInterface
{
    /**
     * @var array<string, TransactionContext>
     */
    private array $contexts = [];

    public function __construct(
        private readonly ConnectionManagerInterface $connections,
        private readonly DatabaseTelemetryEmitter $telemetry,
        private readonly ?DatabaseExecutionScope $scope = null,
        private readonly ?FailureClassifier $failureClassifier = null,
        private readonly ?TransactionRetryCoordinator $retryCoordinator = null,
    ) {
    }

    /**
     * Begin a new transaction (or participate in an existing one) using the
     * given definition.
     *
     * For backward compatibility the first argument may be a connection name
     * string, in which case the default definition is used.
     *
     * @throws TransactionAlreadyActiveException when propagation is Never and a
     *         transaction is already active.
     * @throws TransactionNotActiveException when propagation is Mandatory and
     *         no transaction is active.
     * @throws PropagationNotSupportedException when propagation is RequiresNew.
     * @throws SavepointNotSupportedException when nesting is requested but the
     *         platform does not support savepoints.
     * @throws IsolationNotSupportedException when the requested isolation level
     *         cannot be applied.
     */
    public function begin(
        TransactionDefinition|string|null $definitionOrConnection = null,
        ?string $connectionName = null,
    ): TransactionContext {
        [$definition, $connectionName] = $this->resolveDefinitionAndConnection(
            $definitionOrConnection,
            $connectionName,
        );

        $connection = $this->connections->connection($connectionName);
        $target = $connection->name();
        $context = $this->contexts[$target] ?? null;

        // Propagate validation ------------------------------------------------
        if ($context !== null && $context->isActive()) {
            return match ($definition->propagation) {
                TransactionPropagation::Required,
                TransactionPropagation::Supports => $this->beginNestedTransaction(
                    $connection,
                    $context,
                ),
                TransactionPropagation::Mandatory => $context,
                TransactionPropagation::Never => throw new TransactionAlreadyActiveException(sprintf(
                    'A transaction is already active on connection [%s] and propagation is Never.',
                    $target,
                )),
                TransactionPropagation::RequiresNew => throw new PropagationNotSupportedException(
                    'Propagation REQUIRES_NEW is not supported in this iteration.',
                ),
            };
        }

        // No active transaction: validate propagation that requires one. ------
        if ($definition->propagation === TransactionPropagation::Mandatory) {
            throw new TransactionNotActiveException(sprintf(
                'Propagation Mandatory requires an active transaction on connection [%s].',
                $target,
            ));
        }

        if ($definition->propagation === TransactionPropagation::Supports) {
            // Supports without an active transaction: operate non-transactionally.
            // Return a context that is not backed by a physical transaction.
            $context = new TransactionContext(
                TransactionId::generate(),
                $target,
                $this->scope?->id(),
                $this->scope?->runtimeRequestId(),
                $definition,
            );
            $context->markActive();

            return $context;
        }

        // Start a brand new physical transaction. -----------------------------
        $pdo = $connection->pdo();

        $this->applyIsolation($pdo, $definition, $target);
        $this->applyReadOnly($pdo, $definition, $connection->platform()->capabilities());

        $pdo->beginTransaction();

        $context = new TransactionContext(
            TransactionId::generate(),
            $target,
            $this->scope?->id(),
            $this->scope?->runtimeRequestId(),
            $definition,
        );
        $context->markActive();

        $this->contexts[$target] = $context;
        $this->telemetry->transactionBegan($context);

        return $context;
    }

    public function commit(TransactionDefinition|string|null $connectionName = null): void
    {
        $connection = $this->connections->connection(
            is_string($connectionName) ? $connectionName : null,
        );
        $target = $connection->name();
        $context = $this->requireContext($target);

        $context->assertNotUnknown();

        // Nested commit: release the savepoint.
        if ($context->depth() > 1) {
            $savepoint = $context->releaseNested();
            $connection->pdo()->exec('RELEASE SAVEPOINT ' . $savepoint->value);
            $this->telemetry->savepointReleased($context, $savepoint);

            return;
        }

        if ($context->rollbackOnly()) {
            $this->doRollback($connection, $context);

            throw new TransactionRollbackOnlyException(sprintf(
                'Database transaction [%s] on connection [%s] is marked rollback-only and cannot be committed.',
                $context->id()->value,
                $target,
            ));
        }

        // beforeCommit callbacks ------------------------------------------------
        try {
            $context->callbacks()->dispatchBeforeCommit($context);
        } catch (Throwable $callbackException) {
            // A failed beforeCommit marks the transaction rollback-only and
            // triggers a rollback.
            $context->markRollbackOnly();
            $this->doRollback($connection, $context);

            throw $callbackException;
        }

        // Dispatch commit ------------------------------------------------------
        $context->markCommitting();
        $pdo = $connection->pdo();

        try {
            $pdo->commit();
        } catch (Throwable $commitException) {
            $this->handleCommitFailure($connection, $context, $commitException);

            return; // handleCommitFailure throws after marking terminal state.
        }

        // Commit confirmed. ----------------------------------------------------
        $context->markCommitted();
        $this->telemetry->transactionCommitted($context);

        $context->callbacks()->dispatchAfterCommit($context);
        $context->callbacks()->dispatchAfterCompletion($context, TransactionOutcome::Committed);

        unset($this->contexts[$target]);
    }

    public function rollback(TransactionDefinition|string|null $connectionName = null): void
    {
        $connection = $this->connections->connection(
            is_string($connectionName) ? $connectionName : null,
        );
        $target = $connection->name();
        $context = $this->requireContext($target);

        $context->assertNotUnknown();

        // Nested rollback: rollback to savepoint.
        if ($context->depth() > 1) {
            $savepoint = $context->rollbackNested();
            $pdo = $connection->pdo();
            $pdo->exec('ROLLBACK TO SAVEPOINT ' . $savepoint->value);
            $pdo->exec('RELEASE SAVEPOINT ' . $savepoint->value);
            $this->telemetry->savepointRolledBack($context, $savepoint);

            return;
        }

        $this->doRollback($connection, $context);
    }

    public function markRollbackOnly(TransactionDefinition|string|null $connectionName = null): void
    {
        $target = $this->connections->connection(
            is_string($connectionName) ? $connectionName : null,
        )->name();
        $context = $this->requireContext($target);
        $context->markRollbackOnly();
        $this->telemetry->transactionMarkedRollbackOnly($context);
    }

    public function current(TransactionDefinition|string|null $connectionName = null): ?TransactionContext
    {
        $target = $this->connections->connection(
            is_string($connectionName) ? $connectionName : null,
        )->name();

        return $this->contexts[$target] ?? null;
    }

    public function isActive(TransactionDefinition|string|null $connectionName = null): bool
    {
        $context = $this->current($connectionName);

        return $context !== null && $context->isActive();
    }

    public function rollbackOpenTransactions(): void
    {
        foreach (array_keys($this->contexts) as $connectionName) {
            while (isset($this->contexts[$connectionName]) && $this->contexts[$connectionName]->isActive()) {
                try {
                    $this->rollback($connectionName);
                } catch (TransactionOutcomeUnknownException) {
                    // Unknown outcomes cannot be rolled back; remove from the
                    // active set and continue.
                    unset($this->contexts[$connectionName]);

                    break;
                }
            }
        }
    }

    public function transaction(
        callable $callback,
        ?string $connectionName = null,
        ?TransactionDefinition $definition = null,
    ): mixed {
        $definition ??= TransactionDefinition::defaults();

        // Delegate to the retry coordinator when a retry policy is present.
        if ($definition->retryPolicy !== null && $this->retryCoordinator !== null) {
            $nonRetryDefinition = $definition->withRetryPolicy(null);

            return $this->retryCoordinator->execute(
                $callback,
                $definition->retryPolicy,
                $connectionName,
                $nonRetryDefinition,
            );
        }

        $context = $this->begin($definition, $connectionName);

        try {
            $result = $callback(
                $this->connections->connection($context->connectionName()),
                $context,
            );
        } catch (Throwable $throwable) {
            if ($context->isActive()) {
                try {
                    $this->rollback($context->connectionName());
                } catch (Throwable) {
                    // Ignore rollback errors during exception propagation; the
                    // original exception is the relevant one.
                }
            }

            throw $throwable;
        }

        $this->commit($context->connectionName());

        return $result;
    }

    public function retryCoordinator(): ?TransactionRetryCoordinator
    {
        return $this->retryCoordinator;
    }

    /**
     * @return list<array{
     *     connection: string,
     *     transaction_id: string,
     *     state: string,
     *     outcome: string,
     *     depth: int,
     *     isolation: string,
     *     propagation: string,
     *     read_only: bool,
     *     commit_dispatch_state: string,
     *     scope_id: ?string,
     *     runtime_request_id: ?string
     * }>
     */
    public function snapshotContexts(): array
    {
        return array_values(array_map(
            static fn (TransactionContext $context): array => [
                'connection' => $context->connectionName(),
                'transaction_id' => $context->id()->value,
                'state' => $context->state()->value,
                'outcome' => $context->outcome()->value,
                'depth' => $context->depth(),
                'isolation' => $context->definition()->isolation->value,
                'propagation' => $context->definition()->propagation->value,
                'read_only' => $context->definition()->readOnly,
                'commit_dispatch_state' => $context->commitDispatchState()->value,
                'scope_id' => $context->scopeId(),
                'runtime_request_id' => $context->runtimeRequestId(),
            ],
            $this->contexts,
        ));
    }

    /**
     * @return array{rolled_back: int, skipped: list<string>, errors: list<Throwable>}
     */
    public function rollbackOpenTransactionsOwned(?string $scopeId = null): array
    {
        $rolledBack = 0;
        $skipped = [];
        $errors = [];

        foreach (array_keys($this->contexts) as $connectionName) {
            $context = $this->contexts[$connectionName] ?? null;

            if (
                $scopeId !== null
                && $context !== null
                && $context->scopeId() !== $scopeId
            ) {
                $skipped[] = $connectionName;

                continue;
            }

            while (isset($this->contexts[$connectionName]) && $this->contexts[$connectionName]->isActive()) {
                try {
                    $this->rollback($connectionName);
                    $rolledBack++;
                } catch (TransactionOutcomeUnknownException) {
                    unset($this->contexts[$connectionName]);

                    break;
                } catch (Throwable $exception) {
                    $errors[] = $exception;
                    unset($this->contexts[$connectionName]);

                    break;
                }
            }
        }

        return [
            'rolled_back' => $rolledBack,
            'skipped' => $skipped,
            'errors' => $errors,
        ];
    }

    // ── Private helpers ────────────────────────────────────────────────────

    /**
     * @return array{0: TransactionDefinition, 1: ?string}
     */
    private function resolveDefinitionAndConnection(
        TransactionDefinition|string|null $definitionOrConnection,
        ?string $connectionName,
    ): array {
        if ($definitionOrConnection instanceof TransactionDefinition) {
            return [$definitionOrConnection, $connectionName];
        }

        if (is_string($definitionOrConnection)) {
            return [TransactionDefinition::defaults(), $definitionOrConnection];
        }

        return [TransactionDefinition::defaults(), $connectionName];
    }

    private function beginNestedTransaction(
        \Quantum\Database\Contracts\ConnectionInterface $connection,
        TransactionContext $context,
    ): TransactionContext {
        if (! $connection->platform()->capabilities()->supports('savepoints')) {
            throw new SavepointNotSupportedException(sprintf(
                'Nested transactions are not supported for database connection [%s] because savepoints are unavailable.',
                $context->connectionName(),
            ));
        }

        $savepoint = SavepointId::generate($context->id()->value, $context->depth());
        $connection->pdo()->exec('SAVEPOINT ' . $savepoint->value);
        $context->beginNested($savepoint->value);
        $this->telemetry->savepointCreated($context, $savepoint);

        return $context;
    }

    private function applyIsolation(PDO $pdo, TransactionDefinition $definition, string $connectionName): void
    {
        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

        // SQLite is always SERIALIZABLE except for read-uncommitted which is
        // controlled by the read_uncommitted PRAGMA. The standard
        // "SET TRANSACTION ISOLATION LEVEL" syntax is not supported.
        if ($driver === 'sqlite') {
            if ($definition->isolation === TransactionIsolation::ReadUncommitted) {
                $pdo->exec('PRAGMA read_uncommitted = 1');
            }

            return;
        }

        try {
            $pdo->exec('SET TRANSACTION ISOLATION LEVEL ' . $definition->isolation->value);
        } catch (Throwable $exception) {
            throw new IsolationNotSupportedException(sprintf(
                'Isolation level [%s] is not supported on connection [%s]: %s',
                $definition->isolation->value,
                $connectionName,
                $exception->getMessage(),
            ), 0, $exception);
        }
    }

    private function applyReadOnly(PDO $pdo, TransactionDefinition $definition, $capabilities): void
    {
        if (! $definition->readOnly) {
            return;
        }

        if (! $capabilities->supports('read_only_transactions')) {
            return;
        }

        $pdo->exec('SET TRANSACTION READ ONLY');
    }

    private function doRollback($connection, TransactionContext $context): void
    {
        $pdo = $connection->pdo();
        $context->markRollingBack();

        try {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
        } catch (Throwable $exception) {
            $context->markFailed();
            $this->telemetry->transactionRolledBack($context);

            throw new TransactionRollbackException(sprintf(
                'Failed to roll back transaction [%s] on connection [%s]: %s',
                $context->id()->value,
                $context->connectionName(),
                $exception->getMessage(),
            ), 0, $exception);
        }

        $context->markRolledBack();
        $this->telemetry->transactionRolledBack($context);

        $context->callbacks()->dispatchAfterRollback($context);
        $context->callbacks()->dispatchAfterCompletion($context, TransactionOutcome::RolledBack);

        unset($this->contexts[$context->connectionName()]);
    }

    private function handleCommitFailure(
        $connection,
        TransactionContext $context,
        Throwable $commitException,
    ): void {
        $pdo = $connection->pdo();
        $outcomeUnknown = false;

        try {
            // If the connection is still usable and the transaction is still
            // active, the DB rolled back and the outcome is RolledBack.
            $outcomeUnknown = ! $pdo->inTransaction();
        } catch (Throwable) {
            // Connection is unusable: the outcome is genuinely Unknown.
            $outcomeUnknown = true;
        }

        if ($outcomeUnknown) {
            $context->markUnknown();
            $this->telemetry->transactionUnknown($context);
            $context->callbacks()->dispatchAfterCompletion($context, TransactionOutcome::Unknown);
            unset($this->contexts[$context->connectionName()]);

            throw new TransactionOutcomeUnknownException(sprintf(
                'Transaction [%s] outcome is unknown after commit dispatch failure on connection [%s]: %s',
                $context->id()->value,
                $context->connectionName(),
                $commitException->getMessage(),
            ), 0, $commitException);
        }

        // The DB confirmed the transaction was rolled back.
        $context->markRolledBack();
        $this->telemetry->transactionRolledBack($context);
        $context->callbacks()->dispatchAfterRollback($context);
        $context->callbacks()->dispatchAfterCompletion($context, TransactionOutcome::RolledBack);
        unset($this->contexts[$context->connectionName()]);

        throw new TransactionCommitException(sprintf(
            'Commit of transaction [%s] on connection [%s] failed: %s',
            $context->id()->value,
            $context->connectionName(),
            $commitException->getMessage(),
        ), 0, $commitException);
    }

    private function requireContext(string $connectionName): TransactionContext
    {
        $context = $this->contexts[$connectionName] ?? null;

        if ($context === null || ! $context->isActive()) {
            throw new TransactionNotActiveException(sprintf(
                'No active database transaction exists for connection [%s].',
                $connectionName,
            ));
        }

        return $context;
    }
}
