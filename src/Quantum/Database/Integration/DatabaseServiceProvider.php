<?php

declare(strict_types=1);

namespace Quantum\Database\Integration;

use Quantum\Console\Commands\DatabaseMigrateCommand;
use Quantum\Console\Commands\DatabaseRollbackCommand;
use Quantum\Console\Commands\DatabaseStatusCommand;
use Quantum\Database\Database;
use Quantum\Database\Config\DatabaseConfiguration;
use Quantum\Database\Config\FrameworkDatabaseConfigurationProvider;
use Quantum\Database\Connection\ConnectionDefinitionRegistry;
use Quantum\Database\Connection\ConnectionFactory;
use Quantum\Database\Connection\ConnectionManager;
use Quantum\Database\Contracts\ConnectionManagerInterface;
use Quantum\Database\Contracts\DatabaseInterface;
use Quantum\Database\Contracts\DatabaseConfigurationProviderInterface;
use Quantum\Database\Contracts\QueryExecutorInterface;
use Quantum\Database\Contracts\StatementExecutorInterface;
use Quantum\Database\Contracts\TransactionManagerInterface;
use Quantum\Database\Dialect\DialectResolver;
use Quantum\Database\Driver\DriverRegistry;
use Quantum\Database\Driver\PdoDriver;
use Quantum\Database\Execution\QueryExecutor;
use Quantum\Database\Execution\StatementExecutor;
use Quantum\Database\Migration\MigrationDiscovery;
use Quantum\Database\Migration\MigrationRepository;
use Quantum\Database\Migration\MigrationRunner;
use Quantum\Database\ORM\Contracts\EntityManagerInterface;
use Quantum\Database\ORM\EntityManager;
use Quantum\Database\ORM\IdentityMap;
use Quantum\Database\ORM\Metadata\EntityMetadataRegistry;
use Quantum\Database\ORM\Types\TypeRegistry;
use Quantum\Database\ORM\UnitOfWork;
use Quantum\Database\Platform\PlatformResolver;
use Quantum\Database\Query\Ast\QueryAstFactory;
use Quantum\Database\Query\Builder\DatabaseQueryManager;
use Quantum\Database\Query\Compiler\QueryCompilerInterface;
use Quantum\Database\Query\Compiler\SqlCompiler;
use Quantum\Database\Query\DatabaseQueryRunner;
use Quantum\Database\Runtime\DatabaseContext;
use Quantum\Database\Runtime\DatabaseExecutionScope;
use Quantum\Database\Runtime\DatabaseExecutionScopeFactory;
use Quantum\Database\Runtime\DatabaseScopeLifecycleManager;
use Quantum\Database\Schema\Compiler\SchemaCompiler;
use Quantum\Database\Schema\SchemaManager;
use Quantum\Database\Telemetry\DatabaseTelemetryEmitter;
use Quantum\Database\Transaction\TransactionManager;
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
        $this->app->singleton(QueryAstFactory::class);
        $this->app->singleton(MigrationDiscovery::class);
        $this->app->singleton(DatabaseScopeLifecycleManager::class);
        $this->app->singleton(TypeRegistry::class);
        $this->app->singleton(EntityMetadataRegistry::class, fn(Application $app): EntityMetadataRegistry => new EntityMetadataRegistry(
            $app->make(TypeRegistry::class),
        ));
        $this->app->singleton(DatabaseTelemetryEmitter::class, fn(Application $app): DatabaseTelemetryEmitter => new DatabaseTelemetryEmitter(
            $app->make(\Quantum\Telemetry\Contracts\TelemetryManagerInterface::class),
            $app->make(DatabaseConfiguration::class),
        ));

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
            $app->make(DatabaseTelemetryEmitter::class),
        ));
        $this->app->scoped(StatementExecutorInterface::class, fn(Application $app): StatementExecutorInterface => $app->make(StatementExecutor::class));
        $this->app->scoped(QueryExecutor::class, fn(Application $app): QueryExecutor => new QueryExecutor(
            $app->make(StatementExecutorInterface::class),
        ));
        $this->app->scoped(QueryExecutorInterface::class, fn(Application $app): QueryExecutorInterface => $app->make(QueryExecutor::class));
        $this->app->scoped(SqlCompiler::class, fn(Application $app): SqlCompiler => new SqlCompiler(
            $app->make(QueryAstFactory::class),
            $app->make(ConnectionDefinitionRegistry::class),
            $app->make(DialectResolver::class),
        ));
        $this->app->scoped(QueryCompilerInterface::class, fn(Application $app): QueryCompilerInterface => $app->make(SqlCompiler::class));
        $this->app->scoped(DatabaseQueryRunner::class, fn(Application $app): DatabaseQueryRunner => new DatabaseQueryRunner(
            $app->make(QueryCompilerInterface::class),
            $app->make(QueryExecutorInterface::class),
        ));
        $this->app->scoped(DatabaseQueryManager::class, fn(Application $app): DatabaseQueryManager => new DatabaseQueryManager(
            $app->make(DatabaseQueryRunner::class),
            $app->make(QueryCompilerInterface::class),
        ));
        $this->app->scoped(SchemaCompiler::class, fn(Application $app): SchemaCompiler => new SchemaCompiler(
            $app->make(ConnectionDefinitionRegistry::class),
            $app->make(DialectResolver::class),
        ));
        $this->app->scoped(SchemaManager::class, fn(Application $app): SchemaManager => new SchemaManager(
            $app->make(SchemaCompiler::class),
            $app->make(QueryExecutorInterface::class),
        ));
        $this->app->scoped(MigrationRepository::class, fn(Application $app): MigrationRepository => new MigrationRepository(
            $app->make(SchemaManager::class),
            $app->make(QueryExecutorInterface::class),
            $app->make(DatabaseQueryManager::class),
        ));
        $this->app->scoped(MigrationRunner::class, fn(Application $app): MigrationRunner => new MigrationRunner(
            $app->make(MigrationDiscovery::class),
            $app->make(MigrationRepository::class),
            $app->make(SchemaManager::class),
            $app->make(ConnectionManagerInterface::class),
            $app->make(DatabaseTelemetryEmitter::class),
        ));
        $this->app->scoped(TransactionManager::class, fn(Application $app): TransactionManager => new TransactionManager(
            $app->make(ConnectionManagerInterface::class),
            $app->make(DatabaseTelemetryEmitter::class),
        ));
        $this->app->scoped(TransactionManagerInterface::class, fn(Application $app): TransactionManagerInterface => $app->make(TransactionManager::class));
        $this->app->scoped(IdentityMap::class);
        $this->app->scoped(UnitOfWork::class);
        $this->app->scoped(EntityManager::class, fn(Application $app): EntityManager => new EntityManager(
            $app->make(EntityMetadataRegistry::class),
            $app->make(DatabaseQueryManager::class),
            $app->make(IdentityMap::class),
            $app->make(UnitOfWork::class),
            $app->make(TransactionManagerInterface::class),
        ));
        $this->app->scoped(EntityManagerInterface::class, fn(Application $app): EntityManagerInterface => $app->make(EntityManager::class));
        $this->app->scoped(Database::class, fn(Application $app): Database => new Database(
            $app->make(DatabaseConfiguration::class),
            $app->make(ConnectionManagerInterface::class),
            $app->make(DatabaseQueryManager::class),
            $app->make(SchemaManager::class),
            $app->make(MigrationRepository::class),
            $app->make(MigrationRunner::class),
            $app->make(TransactionManagerInterface::class),
            $app->make(EntityManagerInterface::class),
        ));
        $this->app->scoped(DatabaseInterface::class, fn(Application $app): DatabaseInterface => $app->make(Database::class));

        $this->app->onScopeStart(function (Application $app, RuntimeContext $context): void {
            $app->make(DatabaseScopeLifecycleManager::class)->start($context);
        });

        $this->app->onScopeEnd(function (Application $app, ?RuntimeContext $context): void {
            $app->make(DatabaseScopeLifecycleManager::class)->end($context);
        });
    }

    public function commands(): array
    {
        return [
            DatabaseStatusCommand::class,
            DatabaseMigrateCommand::class,
            DatabaseRollbackCommand::class,
        ];
    }
}
