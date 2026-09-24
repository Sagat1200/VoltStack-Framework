<?php

declare(strict_types=1);

namespace Quantum\Database\Integration;

use Quantum\Database\Config\DatabaseConfiguration;
use Quantum\Database\Config\FrameworkDatabaseConfigurationProvider;
use Quantum\Database\Connection\ConnectionDefinitionRegistry;
use Quantum\Database\Connection\ConnectionFactory;
use Quantum\Database\Connection\ConnectionManager;
use Quantum\Database\Contracts\ConnectionManagerInterface;
use Quantum\Database\Contracts\DatabaseConfigurationProviderInterface;
use Quantum\Database\Contracts\QueryExecutorInterface;
use Quantum\Database\Contracts\StatementExecutorInterface;
use Quantum\Database\Dialect\DialectResolver;
use Quantum\Database\Driver\DriverRegistry;
use Quantum\Database\Driver\PdoDriver;
use Quantum\Database\Execution\QueryExecutor;
use Quantum\Database\Execution\StatementExecutor;
use Quantum\Database\Platform\PlatformResolver;
use Quantum\Database\Runtime\DatabaseContext;
use Quantum\Database\Runtime\DatabaseExecutionScope;
use Quantum\Database\Runtime\DatabaseExecutionScopeFactory;
use Quantum\Database\Runtime\DatabaseScopeLifecycleManager;
use RuntimeException;
use VoltStack\Framework\Application;
use VoltStack\Framework\ServiceProvider;
use VoltStack\Runtime\Context\RuntimeContext;

final class DatabaseServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(DatabaseConfigurationProviderInterface::class, FrameworkDatabaseConfigurationProvider::class);
        $this->app->singleton(DatabaseConfiguration::class, function (Application $app): DatabaseConfiguration {
            /** @var DatabaseConfigurationProviderInterface $provider */
            $provider = $app->make(DatabaseConfigurationProviderInterface::class);

            return $provider->configuration();
        });
        $this->app->singleton(DatabaseExecutionScopeFactory::class);
        $this->app->singleton(DatabaseCompositionRoot::class, fn(Application $app): DatabaseCompositionRoot => new DatabaseCompositionRoot(
            $app->make(DatabaseConfiguration::class),
            $app->make(DatabaseExecutionScopeFactory::class),
        ));
        $this->app->singleton(ConnectionDefinitionRegistry::class, fn(Application $app): ConnectionDefinitionRegistry => new ConnectionDefinitionRegistry(
            $app->make(DatabaseConfiguration::class),
        ));
        $this->app->singleton(DriverRegistry::class, function (): DriverRegistry {
            $registry = new DriverRegistry();
            $pdoSqlite = new PdoDriver('pdo.sqlite');
            $pdoMysql = new PdoDriver('pdo.mysql');
            $pdoPgsql = new PdoDriver('pdo.pgsql');

            $registry->register('sqlite', $pdoSqlite);
            $registry->register('sqlite3', $pdoSqlite);
            $registry->register('pdo.sqlite', $pdoSqlite);
            $registry->register('mysql', $pdoMysql);
            $registry->register('mariadb', $pdoMysql);
            $registry->register('pdo.mysql', $pdoMysql);
            $registry->register('pgsql', $pdoPgsql);
            $registry->register('postgres', $pdoPgsql);
            $registry->register('postgresql', $pdoPgsql);
            $registry->register('pdo.pgsql', $pdoPgsql);

            return $registry;
        });
        $this->app->singleton(PlatformResolver::class);
        $this->app->singleton(DialectResolver::class);
        $this->app->singleton(ConnectionFactory::class);
        $this->app->singleton(DatabaseScopeLifecycleManager::class);

        $this->app->scoped(DatabaseExecutionScope::class, function (Application $app): DatabaseExecutionScope {
            $runtimeContext = RuntimeContext::current();

            if ($runtimeContext === null) {
                throw new RuntimeException('No active runtime context is available for DatabaseExecutionScope.');
            }

            return $app->make(DatabaseExecutionScopeFactory::class)->create(
                $runtimeContext,
                $app->make(DatabaseConfiguration::class),
            );
        });

        $this->app->scoped(DatabaseContext::class, fn(Application $app): DatabaseContext => new DatabaseContext(
            $app->make(DatabaseExecutionScope::class),
            $app->make(DatabaseConfiguration::class),
        ));
        $this->app->scoped(ConnectionManager::class, fn(Application $app): ConnectionManager => new ConnectionManager(
            $app->make(ConnectionDefinitionRegistry::class),
            $app->make(ConnectionFactory::class),
        ));
        $this->app->scoped(ConnectionManagerInterface::class, fn(Application $app): ConnectionManagerInterface => $app->make(ConnectionManager::class));
        $this->app->scoped(StatementExecutor::class, fn(Application $app): StatementExecutor => new StatementExecutor(
            $app->make(ConnectionManagerInterface::class),
        ));
        $this->app->scoped(StatementExecutorInterface::class, fn(Application $app): StatementExecutorInterface => $app->make(StatementExecutor::class));
        $this->app->scoped(QueryExecutor::class, fn(Application $app): QueryExecutor => new QueryExecutor(
            $app->make(StatementExecutorInterface::class),
        ));
        $this->app->scoped(QueryExecutorInterface::class, fn(Application $app): QueryExecutorInterface => $app->make(QueryExecutor::class));

        $this->app->onScopeStart(function (Application $app, RuntimeContext $context): void {
            $app->make(DatabaseScopeLifecycleManager::class)->start($context);
        });

        $this->app->onScopeEnd(function (Application $app, ?RuntimeContext $context): void {
            $app->make(DatabaseScopeLifecycleManager::class)->end($context);
        });
    }
}
