<?php

declare(strict_types=1);

namespace VoltStack\Framework\Provider;

use Quantum\Database\DatabaseContext;
use Quantum\Database\Dbal\Contract\ConnectionInterface;
use Quantum\Database\Dialect\DialectInterface;
use Quantum\Database\Factory\FactoryDiscovery;
use Quantum\Database\Factory\FactoryManager;
use Quantum\Http\Request;
use Quantum\Database\Migration\MigrationDiscovery;
use Quantum\Database\Migration\MigrationLock;
use Quantum\Database\Migration\MigrationRecoveryStore;
use Quantum\Database\Migration\MigrationRepository;
use Quantum\Database\Migration\MigrationRunner;
use Quantum\Database\Operation\Contracts\DatabaseHealthStoreInterface;
use Quantum\Database\Operation\Contracts\DatabaseIdempotencyStoreInterface;
use Quantum\Database\Operation\Contracts\DatabaseRemoteReplayChallengerInterface;
use Quantum\Database\Operation\Contracts\DatabaseRemoteReplayValidatorInterface;
use Quantum\Database\Operation\Contracts\DatabaseTelemetryDispatcherInterface;
use Quantum\Database\Operation\DatabaseCircuitBreaker;
use Quantum\Database\Operation\DatabaseHealthSnapshot;
use Quantum\Database\Operation\DatabaseOperationRuntime;
use Quantum\Database\Operation\DatabaseTelemetryReport;
use Quantum\Database\Operation\DatabaseTelemetryStore;
use Quantum\Database\Operation\Engine\DirectoryDatabaseHealthStore;
use Quantum\Database\Operation\Engine\DirectoryDatabaseIdempotencyStore;
use Quantum\Database\Operation\Engine\ChallengeDatabaseRemoteReplayValidator;
use Quantum\Database\Operation\Engine\InMemoryDatabaseHealthStore;
use Quantum\Database\Operation\Engine\InMemoryDatabaseIdempotencyStore;
use Quantum\Database\Operation\Engine\InMemoryDatabaseTelemetryDispatcher;
use Quantum\Database\Operation\Engine\JsonFileDatabaseHealthStore;
use Quantum\Database\Operation\Engine\JsonLineDatabaseHealthStore;
use Quantum\Database\Operation\Engine\JsonLineDatabaseTelemetryDispatcher;
use Quantum\Database\Operation\Engine\NullDatabaseHealthStore;
use Quantum\Database\Operation\Engine\NullDatabaseIdempotencyStore;
use Quantum\Database\Operation\Engine\NullDatabaseRemoteReplayChallenger;
use Quantum\Database\Operation\Engine\NullDatabaseTelemetryDispatcher;
use Quantum\Database\Schema\SchemaIntrospectorInterface;
use Quantum\Database\Schema\SchemaManager;
use Quantum\Database\Schema\MariadbSchemaIntrospector;
use Quantum\Database\Schema\MysqlSchemaIntrospector;
use Quantum\Database\Schema\PgsqlSchemaIntrospector;
use Quantum\Database\Schema\SqliteSchemaIntrospector;
use Quantum\Database\Seeder\SeederDiscovery;
use Quantum\Database\Seeder\SeederRunner;
use Quantum\Database\Security\DatabaseSecurityContext;
use Quantum\Database\Support\ConnectionRegistry;
use Quantum\Database\Trace\DatabaseDeadline;
use Quantum\Database\Trace\DatabaseTraceContext;
use VoltStack\Framework\Application;
use VoltStack\Framework\ServiceProvider;
use VoltStack\Runtime\Context\RuntimeContext;

final class DatabaseServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ConnectionRegistry::class, function (Application $app): ConnectionRegistry {
            $default = (string) $app->config('database.default', 'primary');
            $connections = $app->config('database.connections', []);

            if (!is_array($connections)) {
                $connections = [];
            }

            return new ConnectionRegistry(
                basePath: $app->basePath(),
                defaultConnection: $default,
                connectionConfigs: $connections,
            );
        });

        $this->app->singleton(ConnectionInterface::class, fn(Application $app): ConnectionInterface => $app->make(ConnectionRegistry::class)->connection());
        $this->app->alias(ConnectionInterface::class, 'db');

        $this->app->singleton(DialectInterface::class, fn(Application $app): DialectInterface => $app->make(ConnectionRegistry::class)->dialect());

        $this->app->singleton(MigrationDiscovery::class, function (Application $app): MigrationDiscovery {
            $paths = $app->config('database.migrations.paths', ['database/migrations']);
            $classes = $app->config('database.migrations.classes', []);

            return new MigrationDiscovery(
                basePath: $app->basePath(),
                paths: is_array($paths) ? array_values($paths) : ['database/migrations'],
                classes: is_array($classes) ? array_values($classes) : [],
            );
        });

        $this->app->singleton(MigrationRepository::class, function (Application $app): MigrationRepository {
            $table = (string) $app->config('database.migrations.table', 'framework_migrations');

            return new MigrationRepository(
                connection: $app->make(ConnectionInterface::class),
                tableName: $table,
            );
        });

        $this->app->singleton(MigrationLock::class, function (Application $app): MigrationLock {
            $connection = $app->make(ConnectionInterface::class);
            $connection->connect();
            $table = (string) $app->config('database.migrations.table', 'framework_migrations');

            return MigrationLock::forConnection(
                locksRoot: $app->storagePath('framework/database/migrations'),
                connectionName: null,
                connection: $connection,
                repositoryTable: $table,
            );
        });

        $this->app->singleton(MigrationRecoveryStore::class, function (Application $app): MigrationRecoveryStore {
            $connection = $app->make(ConnectionInterface::class);
            $connection->connect();
            $table = (string) $app->config('database.migrations.table', 'framework_migrations');

            return MigrationRecoveryStore::forConnection(
                root: $app->storagePath('framework/database/migrations/recovery'),
                connectionName: null,
                connection: $connection,
                repositoryTable: $table,
            );
        });

        $this->app->singleton(MigrationRunner::class, fn(Application $app): MigrationRunner => new MigrationRunner(
            connection: $app->make(ConnectionInterface::class),
            discovery: $app->make(MigrationDiscovery::class),
            repository: $app->make(MigrationRepository::class),
            lock: $app->make(MigrationLock::class),
            recoveryStore: $app->make(MigrationRecoveryStore::class),
        ));

        $this->app->singleton(DatabaseCircuitBreaker::class, fn(): DatabaseCircuitBreaker => new DatabaseCircuitBreaker());
        $this->app->scoped(DatabaseTelemetryStore::class, fn(): DatabaseTelemetryStore => new DatabaseTelemetryStore());
        $this->app->singleton(DatabaseHealthStoreInterface::class, function (Application $app): DatabaseHealthStoreInterface {
            $mode = $app->config('database.health.store', 'auto');

            if ($mode === 'null') {
                return new NullDatabaseHealthStore();
            }

            if ($mode === 'in_memory') {
                return new InMemoryDatabaseHealthStore();
            }

            if ($mode === 'json') {
                $path = $app->config('database.health.json_path');

                if (is_string($path) && trim($path) !== '') {
                    return new JsonFileDatabaseHealthStore(trim($path));
                }

                return new JsonFileDatabaseHealthStore(
                    $app->joinPath($app->storagePath('framework/database'), 'database-health.json'),
                );
            }

            if ($mode === 'directory') {
                $path = $app->config('database.health.directory_path');

                if (is_string($path) && trim($path) !== '') {
                    return new DirectoryDatabaseHealthStore(trim($path));
                }

                return new DirectoryDatabaseHealthStore(
                    $app->joinPath($app->storagePath('framework/database'), 'health-nodes'),
                );
            }

            if ($mode === 'jsonl') {
                $path = $app->config('database.health.jsonl_path');

                if (is_string($path) && trim($path) !== '') {
                    return new JsonLineDatabaseHealthStore(trim($path));
                }

                return new JsonLineDatabaseHealthStore(
                    $app->joinPath($app->storagePath('framework/database'), 'database-health.jsonl'),
                );
            }

            if ($app->isProduction()) {
                return new JsonLineDatabaseHealthStore(
                    $app->joinPath($app->storagePath('framework/database'), 'database-health.jsonl'),
                );
            }

            return new InMemoryDatabaseHealthStore();
        });
        $this->app->singleton(DatabaseIdempotencyStoreInterface::class, function (Application $app): DatabaseIdempotencyStoreInterface {
            $mode = $app->config('database.idempotency.store', 'auto');

            if ($mode === 'null') {
                return new NullDatabaseIdempotencyStore();
            }

            if ($mode === 'in_memory') {
                return new InMemoryDatabaseIdempotencyStore();
            }

            if ($mode === 'directory') {
                $path = $app->config('database.idempotency.directory_path');

                if (is_string($path) && trim($path) !== '') {
                    return new DirectoryDatabaseIdempotencyStore(trim($path));
                }

                return new DirectoryDatabaseIdempotencyStore(
                    $app->joinPath($app->storagePath('framework/database'), 'idempotency'),
                );
            }

            if ($app->isProduction()) {
                return new DirectoryDatabaseIdempotencyStore(
                    $app->joinPath($app->storagePath('framework/database'), 'idempotency'),
                );
            }

            return new InMemoryDatabaseIdempotencyStore();
        });
        $this->app->singleton(
            DatabaseRemoteReplayChallengerInterface::class,
            fn(Application $app): DatabaseRemoteReplayChallengerInterface => new NullDatabaseRemoteReplayChallenger(),
        );
        $this->app->singleton(
            DatabaseRemoteReplayValidatorInterface::class,
            fn(Application $app): DatabaseRemoteReplayValidatorInterface => new ChallengeDatabaseRemoteReplayValidator(
                challenger: $app->make(DatabaseRemoteReplayChallengerInterface::class),
            ),
        );
        $this->app->singleton(DatabaseTelemetryDispatcherInterface::class, function (Application $app): DatabaseTelemetryDispatcherInterface {
            $mode = $app->config('database.observability.dispatcher', 'auto');

            if ($mode === 'null') {
                return new NullDatabaseTelemetryDispatcher();
            }

            if ($mode === 'in_memory') {
                return new InMemoryDatabaseTelemetryDispatcher();
            }

            if ($mode === 'jsonl') {
                $path = $app->config('database.observability.jsonl_path');

                if (is_string($path) && trim($path) !== '') {
                    return new JsonLineDatabaseTelemetryDispatcher(trim($path));
                }

                return new JsonLineDatabaseTelemetryDispatcher(
                    $app->joinPath($app->storagePath('framework/logs'), 'database-telemetry.jsonl'),
                );
            }

            if ($app->isProduction()) {
                return new JsonLineDatabaseTelemetryDispatcher(
                    $app->joinPath($app->storagePath('framework/logs'), 'database-telemetry.jsonl'),
                );
            }

            return new InMemoryDatabaseTelemetryDispatcher();
        });
        $this->app->singleton(DatabaseOperationRuntime::class, fn(Application $app): DatabaseOperationRuntime => new DatabaseOperationRuntime(
            circuitBreaker: $app->make(DatabaseCircuitBreaker::class),
            telemetry: static fn() => $app->make(DatabaseTelemetryStore::class),
            healthStore: static fn() => $app->make(DatabaseHealthStoreInterface::class),
            idempotencyStore: static fn() => $app->make(DatabaseIdempotencyStoreInterface::class),
            remoteReplayValidator: static fn() => $app->make(DatabaseRemoteReplayValidatorInterface::class),
            idempotencyNodeId: static fn() => (string) $app->config(
                'database.idempotency.node_id',
                (string) $app->config('database.health.node_id', (string) $app->config('app.name', 'app')),
            ),
        ));

        $this->app->singleton(SeederDiscovery::class, function (Application $app): SeederDiscovery {
            $paths = $app->config('database.seeders.paths', ['database/seeders']);
            $classes = $app->config('database.seeders.classes', []);

            return new SeederDiscovery(
                basePath: $app->basePath(),
                paths: is_array($paths) ? array_values($paths) : ['database/seeders'],
                classes: is_array($classes) ? array_values($classes) : [],
            );
        });

        $this->app->singleton(SeederRunner::class, fn(Application $app): SeederRunner => new SeederRunner(
            app: $app,
            connection: $app->make(ConnectionInterface::class),
            discovery: $app->make(SeederDiscovery::class),
            factories: $app->make(FactoryManager::class),
        ));

        $this->app->singleton(FactoryDiscovery::class, function (Application $app): FactoryDiscovery {
            $paths = $app->config('database.factories.paths', ['database/factories']);
            $classes = $app->config('database.factories.classes', []);

            return new FactoryDiscovery(
                basePath: $app->basePath(),
                paths: is_array($paths) ? array_values($paths) : ['database/factories'],
                classes: is_array($classes) ? array_values($classes) : [],
            );
        });

        $this->app->singleton(FactoryManager::class, fn(Application $app): FactoryManager => new FactoryManager(
            app: $app,
            discovery: $app->make(FactoryDiscovery::class),
            defaultSeed: (int) $app->config('database.factories.default_seed', 12345),
        ));

        $this->app->singleton(SchemaIntrospectorInterface::class, function (Application $app): SchemaIntrospectorInterface {
            $connection = $app->make(ConnectionInterface::class);
            $connection->connect();

            return match ($connection->getDriverInfo()->driverName) {
                'sqlite' => new SqliteSchemaIntrospector($connection),
                'pgsql' => new PgsqlSchemaIntrospector($connection),
                'mysql' => new MysqlSchemaIntrospector($connection),
                'mariadb' => new MariadbSchemaIntrospector($connection),
                default => throw new \RuntimeException(sprintf(
                    'Schema introspection is not implemented yet for driver [%s].',
                    $connection->getDriverInfo()->driverName,
                )),
            };
        });

        $this->app->singleton(SchemaManager::class, fn(Application $app): SchemaManager => new SchemaManager(
            connection: $app->make(ConnectionInterface::class),
            introspector: $app->make(SchemaIntrospectorInterface::class),
        ));

        $this->app->scoped(DatabaseContext::class, function (Application $app): DatabaseContext {
            $registry = $app->make(ConnectionRegistry::class);
            $connection = $registry->connection();
            $runtimeContext = RuntimeContext::current();

            $tenantId = $app->has('tenant.id')
                ? (string) $app->make('tenant.id')
                : null;

            $timeoutMs = (int) $app->config('database.timeouts.soft_timeout_ms', 30000);
            $maxRows = (int) $app->config('database.query_limits.max_rows', 100000);
            $maxDepth = (int) $app->config('database.query_limits.max_depth', 32);
            $securityPolicies = $app->config('database.security.policies', []);

            if (!is_array($securityPolicies)) {
                $securityPolicies = [];
            }

            $context = new DatabaseContext(
                requestId: $runtimeContext?->requestId() ?? bin2hex(random_bytes(8)),
                deadline: DatabaseDeadline::fromMs($timeoutMs),
                security: new DatabaseSecurityContext(
                    subjectId: null,
                    roles: [],
                    policies: $securityPolicies,
                    redactSensitive: (bool) $app->config('database.security.redact_sensitive', true),
                ),
                trace: DatabaseTraceContext::random(),
                maxRows: $maxRows,
                maxDepth: $maxDepth,
            );

            return $context
                ->withConnection($connection)
                ->withTenant($tenantId);
        });
    }

    public function boot(): void
    {
        $this->app->onScopeStart(function (Application $app, RuntimeContext $context): void {
            $registry = $app->make(ConnectionRegistry::class);
            $maxIdleMs = (int) $app->config('database.timeouts.max_idle_ms_before_ping', 30000);
            $tenantId = self::resolveTenantFromRequest($context->request());

            $registry->refreshIdleConnections($maxIdleMs);
            if ($tenantId !== null && !$app->has('tenant.id')) {
                $app->scopedInstance('tenant.id', $tenantId);
            }
            $context->set('database.scope_ping', true);
            $context->set('database.tenant_id', $tenantId);
            $context->set('database.telemetry', [
                'total_operations' => 0,
                'completed' => 0,
                'failed' => 0,
                'cancelled' => 0,
                'slow_queries' => 0,
                'latest' => [],
            ]);
            $context->set('database.health', (new DatabaseHealthSnapshot(
                totalSegments: 0,
                closedSegments: 0,
                halfOpenSegments: 0,
                openSegments: 0,
                segments: [],
            ))->toArray());
        });

        $this->app->onScopeEnd(function (Application $app, ?RuntimeContext $context): void {
            if (!$context instanceof RuntimeContext) {
                return;
            }

            $telemetry = $app->make(DatabaseTelemetryStore::class);
            $summary = $telemetry->summary();
            $health = $telemetry->health()->toArray();

            $context->set('database.telemetry', $summary);
            $context->set('database.health', $health);

            $dispatcher = $app->make(DatabaseTelemetryDispatcherInterface::class);
            $healthStore = $app->make(DatabaseHealthStoreInterface::class);
            $dbContext = $app->make(DatabaseContext::class);
            $report = new DatabaseTelemetryReport(
                requestId: $dbContext->requestId,
                tenantId: $dbContext->tenantId,
                traceId: $dbContext->trace?->traceId,
                generatedAt: (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format(\DATE_ATOM),
                summary: $summary,
                health: $health,
                nodeId: (string) $app->config('database.health.node_id', (string) $app->config('app.name', 'app')),
            );

            $dispatcher->dispatch($report);
            $healthStore->persist($report);
        });
    }

    public function commands(): array
    {
        return [
            \Quantum\Console\Commands\Database\DbPingCommand::class,
            \Quantum\Console\Commands\Database\DbHealthCommand::class,
            \Quantum\Console\Commands\Database\DbIdempotencyCommand::class,
            \Quantum\Console\Commands\Database\DbQueryCommand::class,
            \Quantum\Console\Commands\Database\DbMigrateCommand::class,
            \Quantum\Console\Commands\Database\DbMigrateRecoverCommand::class,
            \Quantum\Console\Commands\Database\DbRollbackCommand::class,
            \Quantum\Console\Commands\Database\DbMigrateStatusCommand::class,
            \Quantum\Console\Commands\Database\DbSeedCommand::class,
            \Quantum\Console\Commands\Database\DbSeedStatusCommand::class,
            \Quantum\Console\Commands\Database\DbFactoryStatusCommand::class,
            \Quantum\Console\Commands\Database\DbFactorySampleCommand::class,
            \Quantum\Console\Commands\Database\DbSchemaStatusCommand::class,
            \Quantum\Console\Commands\Database\DbSchemaDescribeCommand::class,
            \Quantum\Console\Commands\Database\DbSchemaDiffCommand::class,
            \Quantum\Console\Commands\Database\DbMakeMigrationCommand::class,
        ];
    }

    private static function resolveTenantFromRequest(Request $request): ?string
    {
        $tenantHeader = $request->server('X-Tenant-Id', '');
        if ($tenantHeader === null || $tenantHeader === '') {
            $tenantHeader = $request->server('HTTP_X_TENANT_ID', '');
        }
        if ($tenantHeader === null || $tenantHeader === '') {
            $tenantHeader = $request->header('X-Tenant-Id', '') ?? '';
        }

        if (!is_string($tenantHeader)) {
            return null;
        }

        $tenantId = trim($tenantHeader);
        return $tenantId === '' ? null : $tenantId;
    }
}
