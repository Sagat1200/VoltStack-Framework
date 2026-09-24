<?php

declare(strict_types=1);

namespace Quantum\Database\Runtime;

use Quantum\Database\Connection\ConnectionManager;
use Quantum\Database\Transaction\TransactionManager;
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

        if ($this->app->resolved(TransactionManager::class)) {
            $this->app->make(TransactionManager::class)->rollbackOpenTransactions();
        }

        if ($this->app->resolved(ConnectionManager::class)) {
            $this->app->make(ConnectionManager::class)->disconnectAll();
        }

        $scope->finalize();

        if ($context === null) {
            return;
        }

        $context->set('database.scope_id', $scope->id());
        $context->set('database.scope_finalized', true);
    }
}
