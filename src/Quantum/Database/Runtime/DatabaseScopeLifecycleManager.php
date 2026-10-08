<?php

declare(strict_types=1);

namespace Quantum\Database\Runtime;

use Quantum\Database\Connection\ConnectionManager;
use Quantum\Database\Transaction\TransactionManager;
use Throwable;
use VoltStack\Framework\Application;
use VoltStack\Runtime\Context\RuntimeContext;

final class DatabaseScopeLifecycleManager
{
    public function __construct(
        private readonly Application $app,
    ) {
    }

    public function start(RuntimeContext $context): DatabaseExecutionScope
    {
        /** @var DatabaseExecutionScope $scope */
        $scope = $this->app->make(DatabaseExecutionScope::class);
        $context->set('database.scope_id', $scope->id());
        $context->set('database.default_connection', $scope->configuration()->defaultConnectionName);

        return $scope;
    }

    public function end(?RuntimeContext $context): void
    {
        if (! $this->app->resolved(DatabaseExecutionScope::class)) {
            return;
        }

        /** @var DatabaseExecutionScope $scope */
        $scope = $this->app->make(DatabaseExecutionScope::class);
        $errors = [];
        $transactions = [];
        $connections = [];
        $rolledBack = 0;
        $disconnected = 0;
        $skippedTransactions = [];
        $skippedConnections = [];

        if ($this->app->resolved(TransactionManager::class)) {
            /** @var TransactionManager $transactionManager */
            $transactionManager = $this->app->make(TransactionManager::class);
            $transactions = $transactionManager->snapshotContexts();
            $rollbackReport = $transactionManager->rollbackOpenTransactionsOwned($scope->id());
            $rolledBack = $rollbackReport['rolled_back'];
            $skippedTransactions = $rollbackReport['skipped'];
            $errors = [...$errors, ...$rollbackReport['errors']];
        }

        if ($this->app->resolved(ConnectionManager::class)) {
            /** @var ConnectionManager $connectionManager */
            $connectionManager = $this->app->make(ConnectionManager::class);
            $connections = $connectionManager->snapshotLeases();
            $disconnectReport = $connectionManager->disconnectAllOwned($scope->id());
            $disconnected = $disconnectReport['disconnected'];
            $skippedConnections = $disconnectReport['skipped'];
            $errors = [...$errors, ...$disconnectReport['errors']];
        }

        try {
            $scope->finalize();
        } catch (Throwable $exception) {
            $errors[] = $exception;
        }

        $cleanup = [
            'successful' => $errors === [],
            'rolled_back_transactions' => $rolledBack,
            'disconnected_connections' => $disconnected,
            'skipped_transactions' => $skippedTransactions,
            'skipped_connections' => $skippedConnections,
            'transactions' => $transactions,
            'connections' => $connections,
            'error_count' => count($errors),
            'errors' => array_map(
                static fn (Throwable $exception): array => [
                    'type' => $exception::class,
                    'message' => $exception->getMessage(),
                ],
                $errors,
            ),
        ];
        $scope->set('cleanup', $cleanup);

        if ($context === null) {
            return;
        }

        $context->set('database.scope_id', $scope->id());
        $context->set('database.scope_finalized', true);
        $context->set('database.scope_cleanup', $cleanup);
        $context->set('database.scope_cleanup_successful', $cleanup['successful']);
    }
}
