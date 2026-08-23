<?php

declare(strict_types=1);

namespace VoltStack\Framework\Provider;

use Quantum\Database\DatabaseContext;
use Quantum\Database\Dbal\Contract\ConnectionInterface;
use Quantum\Database\Dialect\DialectInterface;
use Quantum\Http\Request;
use Quantum\Database\Migration\MigrationDiscovery;
use Quantum\Database\Migration\MigrationRepository;
use Quantum\Database\Migration\MigrationRunner;
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

        $this->app->singleton(MigrationRunner::class, fn(Application $app): MigrationRunner => new MigrationRunner(
            connection: $app->make(ConnectionInterface::class),
            discovery: $app->make(MigrationDiscovery::class),
            repository: $app->make(MigrationRepository::class),
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
        });
    }

    public function commands(): array
    {
        return [
            \Quantum\Console\Commands\Database\DbPingCommand::class,
            \Quantum\Console\Commands\Database\DbQueryCommand::class,
            \Quantum\Console\Commands\Database\DbMigrateCommand::class,
            \Quantum\Console\Commands\Database\DbRollbackCommand::class,
            \Quantum\Console\Commands\Database\DbMigrateStatusCommand::class,
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
