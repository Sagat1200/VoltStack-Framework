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
use Quantum\Database\Operation\Contracts\DatabaseTelemetryAlertSamplingStoreInterface;
use Quantum\Database\Operation\Contracts\DatabaseTelemetryDispatcherInterface;
use Quantum\Database\Operation\DatabaseCircuitBreaker;
use Quantum\Database\Operation\DatabaseHealthSnapshot;
use Quantum\Database\Operation\DatabaseOperationRuntime;
use Quantum\Database\Operation\DatabaseTelemetryReport;
use Quantum\Database\Operation\DatabaseTelemetryStore;
use Quantum\Database\Operation\Engine\DirectoryDatabaseHealthStore;
use Quantum\Database\Operation\Engine\DirectoryDatabaseIdempotencyStore;
use Quantum\Database\Operation\Engine\DirectoryDatabaseTelemetryAlertSamplingStore;
use Quantum\Database\Operation\Engine\ChallengeDatabaseRemoteReplayValidator;
use Quantum\Database\Operation\Engine\DatabaseTelemetrySignalAlertSampler;
use Quantum\Database\Operation\Engine\DatabaseTelemetrySignalMapper;
use Quantum\Database\Operation\Engine\DatabaseRemoteReplayChallengeEndpointResolver;
use Quantum\Database\Operation\Engine\DatabaseRemoteReplayChallengeSigner;
use Quantum\Database\Operation\Engine\HttpDatabaseTelemetryDispatcher;
use Quantum\Database\Operation\Engine\InMemoryDatabaseHealthStore;
use Quantum\Database\Operation\Engine\InMemoryDatabaseIdempotencyStore;
use Quantum\Database\Operation\Engine\InMemoryDatabaseTelemetryAlertSamplingStore;
use Quantum\Database\Operation\Engine\InMemoryDatabaseTelemetryDispatcher;
use Quantum\Database\Operation\Engine\HttpDatabaseRemoteReplayChallenger;
use Quantum\Database\Operation\Engine\JsonFileDatabaseHealthStore;
use Quantum\Database\Operation\Engine\JsonLineDatabaseHealthStore;
use Quantum\Database\Operation\Engine\JsonLineDatabaseTelemetryDispatcher;
use Quantum\Database\Operation\Engine\NullDatabaseHealthStore;
use Quantum\Database\Operation\Engine\NullDatabaseIdempotencyStore;
use Quantum\Database\Operation\Engine\NullDatabaseRemoteReplayChallenger;
use Quantum\Database\Operation\Engine\NullDatabaseTelemetryDispatcher;
use Quantum\Database\Operation\Engine\OpenTelemetryDatabaseTelemetryDispatcher;
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
use Quantum\Routing\Router;
use VoltStack\Framework\Application;
use VoltStack\Framework\ServiceProvider;
use VoltStack\Runtime\Context\RuntimeContext;
use VoltStack\Runtime\Protocol\DatabaseRemoteReplayChallengeController;

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
            DatabaseRemoteReplayChallengeEndpointResolver::class,
            function (Application $app): DatabaseRemoteReplayChallengeEndpointResolver {
                $endpointMap = $app->config('database.idempotency.remote_replay_challenge.endpoint_map', []);
                if (! is_array($endpointMap)) {
                    $endpointMap = [];
                }
                $knownNodes = $app->config('database.idempotency.remote_replay_challenge.known_nodes', []);
                if (! is_array($knownNodes)) {
                    $knownNodes = [];
                }
                $discoveryViaHealth = (bool) $app->config(
                    'database.idempotency.remote_replay_challenge.discovery_via_health',
                    true,
                );
                $healthDiscoveryLimit = max(1, (int) $app->config(
                    'database.idempotency.remote_replay_challenge.discovery_health_limit',
                    250,
                ));
                $healthDiscoveryMode = (string) $app->config(
                    'database.idempotency.remote_replay_challenge.discovery_health_mode',
                    'allow',
                );
                $healthAdvertisementMaxAgeSeconds = max(0, (int) $app->config(
                    'database.idempotency.remote_replay_challenge.discovery_health_max_age_seconds',
                    300,
                ));
                $trustedNodes = $app->config('database.idempotency.remote_replay_challenge.discovery_trusted_nodes', []);
                if (! is_array($trustedNodes)) {
                    $trustedNodes = [];
                }
                $strategyOrder = $app->config('database.idempotency.remote_replay_challenge.strategy_order', []);
                if (! is_array($strategyOrder)) {
                    $strategyOrder = [];
                }

                return new DatabaseRemoteReplayChallengeEndpointResolver(
                    endpointMap: array_filter(array_map(
                        static fn(mixed $value): string => is_string($value) ? trim($value) : '',
                        $endpointMap,
                    )),
                    endpointTemplate: trim((string) $app->config(
                        'database.idempotency.remote_replay_challenge.endpoint_template',
                        '',
                    )) ?: null,
                    defaultPath: trim((string) $app->config(
                        'database.idempotency.remote_replay_challenge.path',
                        '/_volt/db/remote-replay/challenge',
                    )) ?: null,
                    knownNodes: array_values(array_filter(array_map(
                        static fn(mixed $value): string => is_string($value) ? trim($value) : '',
                        $knownNodes,
                    ))),
                    trustedNodes: array_values(array_filter(array_map(
                        static fn(mixed $value): string => is_string($value) ? trim($value) : '',
                        $trustedNodes,
                    ))),
                    healthDiscoveryMode: $healthDiscoveryMode,
                    healthAdvertisementMaxAgeSeconds: $healthAdvertisementMaxAgeSeconds,
                    strategyOrder: array_values(array_filter(array_map(
                        static fn(mixed $value): string => is_string($value) ? trim($value) : '',
                        $strategyOrder,
                    ))),
                    advertisedEndpointProvider: $discoveryViaHealth
                        ? static function () use ($app, $healthDiscoveryLimit): array {
                            $store = $app->make(DatabaseHealthStoreInterface::class);
                            $reports = $store->recent($healthDiscoveryLimit);
                            $advertised = [];

                            foreach ($reports as $report) {
                                if (!$report instanceof DatabaseTelemetryReport) {
                                    continue;
                                }

                                $summary = is_array($report->summary) ? $report->summary : [];
                                $remoteReplayChallenge = is_array($summary['remote_replay_challenge'] ?? null)
                                    ? $summary['remote_replay_challenge']
                                    : [];
                                $advertisement = is_array($remoteReplayChallenge['cluster_advertisement'] ?? null)
                                    ? $remoteReplayChallenge['cluster_advertisement']
                                    : null;
                                $nodeId = trim((string) (($advertisement['node_id'] ?? null) ?: $report->nodeId ?: ''));
                                $endpoint = trim((string) (($advertisement['endpoint'] ?? null) ?: ''));

                                if ($nodeId === '' || $endpoint === '') {
                                    continue;
                                }

                                $advertisement['generated_at'] = $report->generatedAt;
                                $advertisement['report_node_id'] = $report->nodeId;
                                $advertised[$nodeId] = $advertisement;
                            }

                            return $advertised;
                        }
                        : null,
                );
            },
        );
        $this->app->singleton(
            DatabaseRemoteReplayChallengeSigner::class,
            fn(Application $app): DatabaseRemoteReplayChallengeSigner => new DatabaseRemoteReplayChallengeSigner($app),
        );
        $this->app->singleton(
            DatabaseRemoteReplayChallengerInterface::class,
            function (Application $app): DatabaseRemoteReplayChallengerInterface {
                $transport = strtolower((string) $app->config('database.idempotency.remote_replay_challenge.transport', 'auto'));
                $endpointMap = $app->config('database.idempotency.remote_replay_challenge.endpoint_map', []);
                if (! is_array($endpointMap)) {
                    $endpointMap = [];
                }
                $endpointTemplate = trim((string) $app->config(
                    'database.idempotency.remote_replay_challenge.endpoint_template',
                    '',
                ));
                $discoveryViaHealth = (bool) $app->config(
                    'database.idempotency.remote_replay_challenge.discovery_via_health',
                    true,
                );

                if ($transport === 'null') {
                    return new NullDatabaseRemoteReplayChallenger();
                }

                if ($transport === 'http' || ($transport === 'auto' && ($endpointMap !== [] || $endpointTemplate !== '' || $discoveryViaHealth))) {
                    return new HttpDatabaseRemoteReplayChallenger(
                        signer: $app->make(DatabaseRemoteReplayChallengeSigner::class),
                        endpointResolver: $app->make(DatabaseRemoteReplayChallengeEndpointResolver::class),
                        requestTimeoutMs: max(250, (int) $app->config(
                            'database.idempotency.remote_replay_challenge.request_timeout_ms',
                            2000,
                        )),
                        failureCooldownSeconds: max(0, (int) $app->config(
                            'database.idempotency.remote_replay_challenge.failure_cooldown_seconds',
                            30,
                        )),
                    );
                }

                return new NullDatabaseRemoteReplayChallenger();
            },
        );
        $this->app->singleton(
            DatabaseRemoteReplayValidatorInterface::class,
            fn(Application $app): DatabaseRemoteReplayValidatorInterface => new ChallengeDatabaseRemoteReplayValidator(
                challenger: $app->make(DatabaseRemoteReplayChallengerInterface::class),
            ),
        );
        $this->app->singleton(DatabaseTelemetryAlertSamplingStoreInterface::class, function (Application $app): DatabaseTelemetryAlertSamplingStoreInterface {
            $mode = strtolower(trim((string) $app->config('database.observability.sqg_pipeline.alert_sampling_store', 'auto')));
            $windowSeconds = max(0, (int) $app->config('database.observability.sqg_pipeline.alert_sampling_window_seconds', 900));

            if ($mode === 'directory') {
                $path = $app->config('database.observability.sqg_pipeline.alert_sampling_directory_path');

                if (is_string($path) && trim($path) !== '') {
                    return new DirectoryDatabaseTelemetryAlertSamplingStore(trim($path), $windowSeconds);
                }

                return new DirectoryDatabaseTelemetryAlertSamplingStore(
                    $app->joinPath($app->storagePath('framework/database'), 'telemetry-alert-sampling'),
                    $windowSeconds,
                );
            }

            if ($mode === 'in_memory') {
                return new InMemoryDatabaseTelemetryAlertSamplingStore($windowSeconds);
            }

            if ($app->isProduction()) {
                return new DirectoryDatabaseTelemetryAlertSamplingStore(
                    $app->joinPath($app->storagePath('framework/database'), 'telemetry-alert-sampling'),
                    $windowSeconds,
                );
            }

            return new InMemoryDatabaseTelemetryAlertSamplingStore($windowSeconds);
        });
        $this->app->singleton(DatabaseTelemetrySignalMapper::class, function (Application $app): DatabaseTelemetrySignalMapper {
            $thresholds = self::resolveSqgPipelineProfileConfig(
                $app,
                'database.observability.sqg_pipeline.alert_profile',
                'database.observability.sqg_pipeline.alert_profiles',
                'database.observability.sqg_pipeline.alert_thresholds',
            );
            $severities = self::resolveSqgPipelineProfileConfig(
                $app,
                'database.observability.sqg_pipeline.alert_severity_profile',
                'database.observability.sqg_pipeline.alert_severity_profiles',
                'database.observability.sqg_pipeline.alert_severities',
            );

            return new DatabaseTelemetrySignalMapper(
                is_array($thresholds) ? $thresholds : [],
                is_array($severities) ? $severities : [],
            );
        });
        $this->app->singleton(DatabaseTelemetrySignalAlertSampler::class, function (Application $app): DatabaseTelemetrySignalAlertSampler {
            $samplingProfile = strtolower(trim((string) $app->config(
                'database.observability.sqg_pipeline.alert_sampling_profile',
                'production',
            )));
            $sampling = self::resolveSqgPipelineProfileConfig(
                $app,
                'database.observability.sqg_pipeline.alert_sampling_profile',
                'database.observability.sqg_pipeline.alert_sampling_profiles',
                'database.observability.sqg_pipeline.alert_sampling',
            );

            return new DatabaseTelemetrySignalAlertSampler(
                is_array($sampling) ? $sampling : [],
                $app->make(DatabaseTelemetryAlertSamplingStoreInterface::class),
                $samplingProfile !== '' ? $samplingProfile : 'custom',
            );
        });
        $this->app->singleton(DatabaseTelemetryDispatcherInterface::class, function (Application $app): DatabaseTelemetryDispatcherInterface {
            $mode = $app->config('database.observability.dispatcher', 'auto');
            $mapper = $app->make(DatabaseTelemetrySignalMapper::class);
            $alertSampler = $app->make(DatabaseTelemetrySignalAlertSampler::class);

            if ($mode === 'null') {
                return new NullDatabaseTelemetryDispatcher($mapper, $alertSampler);
            }

            if ($mode === 'in_memory') {
                return new InMemoryDatabaseTelemetryDispatcher(mapper: $mapper, alertSampler: $alertSampler);
            }

            if ($mode === 'jsonl') {
                $path = $app->config('database.observability.jsonl_path');

                if (is_string($path) && trim($path) !== '') {
                    return new JsonLineDatabaseTelemetryDispatcher(trim($path), mapper: $mapper, alertSampler: $alertSampler);
                }

                return new JsonLineDatabaseTelemetryDispatcher(
                    $app->joinPath($app->storagePath('framework/logs'), 'database-telemetry.jsonl'),
                    mapper: $mapper,
                    alertSampler: $alertSampler,
                );
            }

            if ($mode === 'webhook') {
                $endpoint = trim((string) $app->config('database.observability.webhook_url', ''));
                if ($endpoint === '') {
                    throw new \RuntimeException('Database telemetry webhook dispatcher requires [database.observability.webhook_url].');
                }

                $headers = $app->config('database.observability.webhook_headers', []);
                if (!is_array($headers)) {
                    $headers = [];
                }

                $normalizedHeaders = [];
                foreach ($headers as $name => $value) {
                    $headerName = trim((string) $name);
                    $headerValue = trim((string) $value);
                    if ($headerName === '' || $headerValue === '') {
                        continue;
                    }

                    $normalizedHeaders[$headerName] = $headerValue;
                }

                return new HttpDatabaseTelemetryDispatcher(
                    endpoint: $endpoint,
                    headers: $normalizedHeaders,
                    requestTimeoutMs: max(250, (int) $app->config('database.observability.webhook_timeout_ms', 2000)),
                    mapper: $mapper,
                    alertSampler: $alertSampler,
                );
            }

            if ($mode === 'opentelemetry') {
                $endpoint = trim((string) $app->config('database.observability.opentelemetry.endpoint', ''));
                if ($endpoint === '') {
                    throw new \RuntimeException('Database OpenTelemetry dispatcher requires [database.observability.opentelemetry.endpoint].');
                }

                $headers = $app->config('database.observability.opentelemetry.headers', []);
                if (!is_array($headers)) {
                    $headers = [];
                }

                $normalizedHeaders = [];
                foreach ($headers as $name => $value) {
                    $headerName = trim((string) $name);
                    $headerValue = trim((string) $value);
                    if ($headerName === '' || $headerValue === '') {
                        continue;
                    }

                    $normalizedHeaders[$headerName] = $headerValue;
                }

                return new OpenTelemetryDatabaseTelemetryDispatcher(
                    endpoint: $endpoint,
                    serviceName: trim((string) $app->config(
                        'database.observability.opentelemetry.service_name',
                        (string) $app->config('app.name', 'voltstack-database'),
                    )) ?: 'voltstack-database',
                    serviceNamespace: trim((string) $app->config(
                        'database.observability.opentelemetry.service_namespace',
                        'voltstack.database',
                    )) ?: 'voltstack.database',
                    scopeName: trim((string) $app->config(
                        'database.observability.opentelemetry.scope_name',
                        'voltstack.database',
                    )) ?: 'voltstack.database',
                    scopeVersion: trim((string) $app->config(
                        'database.observability.opentelemetry.scope_version',
                        '1.0.0',
                    )) ?: '1.0.0',
                    headers: $normalizedHeaders,
                    requestTimeoutMs: max(250, (int) $app->config('database.observability.opentelemetry.timeout_ms', 2000)),
                    mapper: $mapper,
                    alertSampler: $alertSampler,
                );
            }

            if ($app->isProduction()) {
                return new JsonLineDatabaseTelemetryDispatcher(
                    $app->joinPath($app->storagePath('framework/logs'), 'database-telemetry.jsonl'),
                    mapper: $mapper,
                    alertSampler: $alertSampler,
                );
            }

            return new InMemoryDatabaseTelemetryDispatcher(mapper: $mapper, alertSampler: $alertSampler);
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
            remoteReplayChallengeSigner: static fn() => $app->make(DatabaseRemoteReplayChallengeSigner::class),
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
        $challengePath = trim((string) $this->app->config(
            'database.idempotency.remote_replay_challenge.path',
            '/_volt/db/remote-replay/challenge',
        ));
        if ($challengePath !== '') {
            $this->app->make(Router::class)->post($challengePath, DatabaseRemoteReplayChallengeController::class)->meta([
                'context' => 'api',
                'transport' => 'internal',
                'endpoint' => 'volt.database.remote_replay.challenge',
                'protocol' => 'volt-db',
            ]);
        }

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
                'remote_replay_challenge' => [
                    'observed_operations' => 0,
                    'verified' => 0,
                    'unavailable' => 0,
                    'rejected' => 0,
                    'reused_receipts' => 0,
                    'compatible' => 0,
                    'incompatible' => 0,
                    'protocols' => [],
                    'request_key_ids' => [],
                    'response_key_ids' => [],
                ],
                'sqg_pipeline' => [
                    'observed_operations' => 0,
                    'optimizer_strategies' => [],
                    'selected_candidates' => [],
                    'planner_logical_roots' => [],
                    'planner_physical_roots' => [],
                    'join_reorder_selected' => 0,
                    'join_reorder_signatures' => [],
                    'estimated_cost_total' => 0.0,
                    'estimated_cost_avg' => 0.0,
                    'estimated_cost_min' => null,
                    'estimated_cost_max' => null,
                    'cost_delta_vs_baseline_total' => 0.0,
                    'cost_delta_vs_baseline_avg' => 0.0,
                    'cost_delta_vs_baseline_max' => 0.0,
                    'candidate_count_total' => 0,
                    'candidate_count_avg' => 0.0,
                    'candidate_count_max' => 0,
                ],
                'alert_sampling' => DatabaseTelemetryStore::emptyAlertSamplingSummary(),
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
            $nodeId = (string) $app->config('database.health.node_id', (string) $app->config('app.name', 'app'));

            $challengeAdvertisement = self::buildRemoteReplayChallengeAdvertisement($app, $nodeId);
            if ($challengeAdvertisement !== null) {
                $remoteReplayChallenge = is_array($summary['remote_replay_challenge'] ?? null)
                    ? $summary['remote_replay_challenge']
                    : [];
                $remoteReplayChallenge['cluster_advertisement'] = $challengeAdvertisement;
                $summary['remote_replay_challenge'] = $remoteReplayChallenge;
            }

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
                nodeId: $nodeId,
            );

            $dispatchedReport = $dispatcher->dispatch($report);
            $alertSampling = is_array($dispatchedReport->summary['alert_sampling'] ?? null)
                ? $dispatchedReport->summary['alert_sampling']
                : null;
            if ($alertSampling !== null) {
                $telemetry->attachAlertSampling($alertSampling);
            }

            $context->set('database.telemetry', $dispatchedReport->summary);
            $context->set('database.health', $health);

            $healthStore->persist($dispatchedReport);
        });
    }

    public function commands(): array
    {
        return [
            \Quantum\Console\Commands\Database\DbPingCommand::class,
            \Quantum\Console\Commands\Database\DbHealthCommand::class,
            \Quantum\Console\Commands\Database\DbIdempotencyCommand::class,
            \Quantum\Console\Commands\Database\DbQueryCommand::class,
            \Quantum\Console\Commands\Database\DbSqgSelectCommand::class,
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

    /**
     * @return array<string, mixed>|null
     */
    private static function buildRemoteReplayChallengeAdvertisement(Application $app, string $nodeId): ?array
    {
        $transport = strtolower((string) $app->config('database.idempotency.remote_replay_challenge.transport', 'auto'));
        if ($transport === 'null') {
            return null;
        }

        $path = trim((string) $app->config(
            'database.idempotency.remote_replay_challenge.path',
            '/_volt/db/remote-replay/challenge',
        ));
        if ($path === '') {
            $path = '/_volt/db/remote-replay/challenge';
        }

        $endpoint = trim((string) $app->config('database.idempotency.remote_replay_challenge.advertised_endpoint', ''));
        $source = 'advertised_endpoint';

        if ($endpoint === '') {
            $appUrl = trim((string) $app->config('app.url', ''));
            if ($appUrl !== '') {
                $endpoint = rtrim($appUrl, '/') . '/' . ltrim($path, '/');
                $source = 'app_url';
            }
        }

        if ($endpoint === '') {
            return null;
        }

        $signer = $app->make(DatabaseRemoteReplayChallengeSigner::class);

        return [
            'node_id' => $nodeId,
            'endpoint' => $endpoint,
            'path' => $path,
            'source' => $source,
            'transport' => $transport,
            'protocol' => $signer->protocol(),
            'supported_protocols' => $signer->supportedProtocols(),
            'capabilities' => $signer->capabilities(),
            'key_id' => $signer->activeKeyId(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function resolveSqgPipelineProfileConfig(
        Application $app,
        string $profileKey,
        string $profilesKey,
        string $overridesKey,
        string $fallbackProfile = 'production',
    ): array {
        $profile = strtolower(trim((string) $app->config($profileKey, $fallbackProfile)));
        $profiles = $app->config($profilesKey, []);
        $profileValues = [];

        if (is_array($profiles) && is_array($profiles[$profile] ?? null)) {
            $profileValues = $profiles[$profile];
        } elseif (is_array($profiles) && is_array($profiles[$fallbackProfile] ?? null)) {
            $profileValues = $profiles[$fallbackProfile];
        }

        $overrides = $app->config($overridesKey, []);
        if (!is_array($overrides)) {
            $overrides = [];
        }

        $resolved = [];
        foreach (
            array_merge(
                is_array($profileValues) ? $profileValues : [],
                $overrides,
            ) as $key => $value
        ) {
            if ($value === null) {
                continue;
            }

            $resolved[(string) $key] = $value;
        }

        return $resolved;
    }
}
