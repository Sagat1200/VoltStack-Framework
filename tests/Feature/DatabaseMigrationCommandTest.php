<?php

declare(strict_types=1);

namespace VoltStack\Test\Feature;

use PHPUnit\Framework\TestCase;
use Quantum\Console\ConsoleApplication;
use Quantum\Console\Output;
use Quantum\Database\Dbal\Contract\ConnectionInterface;
use Quantum\Database\Migration\MigrationLock;
use Quantum\Database\Migration\MigrationLockLease;
use VoltStack\Framework\Application;
use VoltStack\Framework\Provider\DatabaseServiceProvider;

final class DatabaseMigrationCommandTest extends TestCase
{
    private string $basePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->basePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'voltstack-db-migrations-' . bin2hex(random_bytes(6));
        $this->makeTempProject($this->basePath);
    }

    protected function tearDown(): void
    {
        $this->deleteDirectory($this->basePath);

        parent::tearDown();
    }

    public function test_cli_migrate_applies_pending_migrations_and_reports_status(): void
    {
        $migrate = $this->runConsole(['volt', 'db:migrate', '--step=1']);
        self::assertSame(0, $migrate['exit']);
        self::assertStringContainsString('Plan de migración: 1 pendiente(s).', $migrate['stdout']);
        self::assertStringContainsString('Fingerprint:', $migrate['stdout']);
        self::assertStringContainsString('Contexto: driver=sqlite', $migrate['stdout']);
        self::assertStringContainsString('[tx] 202608220001', $migrate['stdout']);
        self::assertStringContainsString('Migraciones aplicadas: 1', $migrate['stdout']);
        self::assertStringContainsString('202608220001', $migrate['stdout']);
        self::assertStringContainsString('Verify: OK', $migrate['stdout']);
        self::assertStringContainsString('verified=1', $migrate['stdout']);
        self::assertStringContainsString('remaining_pending=1', $migrate['stdout']);

        $status = $this->runConsole(['volt', 'db:migrate-status']);
        self::assertSame(0, $status['exit']);
        self::assertStringContainsString('[APPLIED] 202608220001', $status['stdout']);
        self::assertStringContainsString('[PENDING] 202608220002', $status['stdout']);

        $app = $this->loadApp();
        self::assertTrue($this->tableExists($app, 'f16_users'));
        self::assertFalse($this->tableExists($app, 'f16_logs'));
    }

    public function test_cli_migrate_pretend_does_not_change_database_state(): void
    {
        $pretend = $this->runConsole(['volt', 'db:migrate', '--pretend']);

        self::assertSame(0, $pretend['exit']);
        self::assertStringContainsString('Plan de migración: 2 pendiente(s).', $pretend['stdout']);
        self::assertStringContainsString('Fingerprint:', $pretend['stdout']);
        self::assertStringContainsString('Contexto: driver=sqlite', $pretend['stdout']);
        self::assertStringContainsString('[tx] 202608220001', $pretend['stdout']);
        self::assertStringContainsString('[non-tx] 202608220002', $pretend['stdout']);
        self::assertStringContainsString('Dry-run activado: no se aplicaron cambios.', $pretend['stdout']);

        $app = $this->loadApp();
        self::assertFalse($this->tableExists($app, 'f16_users'));
        self::assertFalse($this->tableExists($app, 'f16_logs'));
    }

    public function test_cli_rollback_reverts_latest_batch(): void
    {
        $migrate = $this->runConsole(['volt', 'db:migrate']);
        self::assertSame(0, $migrate['exit']);
        self::assertStringContainsString('Verify: OK', $migrate['stdout']);
        self::assertStringContainsString('verified=2', $migrate['stdout']);
        self::assertStringContainsString('remaining_pending=0', $migrate['stdout']);

        $app = $this->loadApp();
        self::assertTrue($this->tableExists($app, 'f16_users'));
        self::assertTrue($this->tableExists($app, 'f16_logs'));

        $rollback = $this->runConsole(['volt', 'db:rollback']);
        self::assertSame(0, $rollback['exit']);
        self::assertStringContainsString('Migraciones revertidas: 2', $rollback['stdout']);
        self::assertStringContainsString('202608220002', $rollback['stdout']);
        self::assertStringContainsString('202608220001', $rollback['stdout']);

        $freshApp = $this->loadApp();
        self::assertFalse($this->tableExists($freshApp, 'f16_users'));
        self::assertFalse($this->tableExists($freshApp, 'f16_logs'));

        $status = $this->runConsole(['volt', 'db:migrate-status']);
        self::assertStringContainsString('[PENDING] 202608220001', $status['stdout']);
        self::assertStringContainsString('[PENDING] 202608220002', $status['stdout']);
    }

    public function test_cli_migrate_fails_when_migration_lock_is_already_held(): void
    {
        $lease = $this->acquireMigrationLock();

        try {
            $migrate = $this->runConsole(['volt', 'db:migrate']);
        } finally {
            $lease->release();
        }

        self::assertSame(1, $migrate['exit']);
        self::assertStringContainsString('db:migrate failed:', $migrate['stderr']);
        self::assertStringContainsString('Migration lock is already held', $migrate['stderr']);
    }

    public function test_cli_rollback_fails_when_migration_lock_is_already_held(): void
    {
        $migrate = $this->runConsole(['volt', 'db:migrate']);
        self::assertSame(0, $migrate['exit']);

        $lease = $this->acquireMigrationLock();

        try {
            $rollback = $this->runConsole(['volt', 'db:rollback']);
        } finally {
            $lease->release();
        }

        self::assertSame(1, $rollback['exit']);
        self::assertStringContainsString('db:rollback failed:', $rollback['stderr']);
        self::assertStringContainsString('Migration lock is already held', $rollback['stderr']);
    }

    public function test_cli_migrate_reports_checkpoint_when_execution_fails_mid_plan(): void
    {
        $this->appendFailingMigration();

        $migrate = $this->runConsole(['volt', 'db:migrate']);

        self::assertSame(1, $migrate['exit']);
        self::assertStringContainsString('db:migrate failed: failure=permanent', $migrate['stderr']);
        self::assertStringContainsString('phase=execute', $migrate['stderr']);
        self::assertStringContainsString('position=3/3', $migrate['stderr']);
        self::assertStringContainsString('completed=2', $migrate['stderr']);
        self::assertStringContainsString('failed_version=202608220003', $migrate['stderr']);
        self::assertStringContainsString('failed_migration=TempMigrations\\', $migrate['stderr']);
        self::assertStringContainsString('boom failing migration', $migrate['stderr']);

        $status = $this->runConsole(['volt', 'db:migrate-status']);
        self::assertStringContainsString('[APPLIED] 202608220001', $status['stdout']);
        self::assertStringContainsString('[APPLIED] 202608220002', $status['stdout']);
        self::assertStringContainsString('[PENDING] 202608220003', $status['stdout']);

        $app = $this->loadApp();
        self::assertTrue($this->tableExists($app, 'f16_users'));
        self::assertTrue($this->tableExists($app, 'f16_logs'));
        self::assertFalse($this->tableExists($app, 'f16_failures'));
    }

    /**
     * @param array<int, string> $argv
     * @return array{exit:int,stdout:string,stderr:string}
     */
    private function runConsole(array $argv): array
    {
        $output = new Output();
        $console = new ConsoleApplication($this->basePath, output: $output);

        return [
            'exit' => $console->run($argv),
            'stdout' => $output->stdout(),
            'stderr' => $output->stderr(),
        ];
    }

    private function loadApp(): Application
    {
        /** @var Application $app */
        $app = require $this->basePath . DIRECTORY_SEPARATOR . 'bootstrap' . DIRECTORY_SEPARATOR . 'app.php';

        return $app;
    }

    private function tableExists(Application $app, string $table): bool
    {
        $connection = $app->make(ConnectionInterface::class);
        $row = $connection
            ->executeQuery(
                "SELECT name FROM sqlite_master WHERE type = 'table' AND name = ?",
                [$table],
            )
            ->fetchOneAssoc();

        return is_array($row);
    }

    private function acquireMigrationLock(?string $connectionName = null): MigrationLockLease
    {
        $app = $this->loadApp();
        $connection = $app->make(ConnectionInterface::class);
        $connection->connect();

        $lock = MigrationLock::forConnection(
            locksRoot: $app->storagePath('framework/database/migrations'),
            connectionName: $connectionName,
            connection: $connection,
            repositoryTable: (string) $app->config('database.migrations.table', 'framework_migrations'),
        );

        return $lock->acquire();
    }

    private function appendFailingMigration(): void
    {
        $namespace = 'TempMigrations\\T' . substr(md5($this->basePath), 0, 8);
        $migrationPath = $this->basePath . DIRECTORY_SEPARATOR . 'database' . DIRECTORY_SEPARATOR . 'migrations';

        file_put_contents(
            $migrationPath . DIRECTORY_SEPARATOR . '202608220003_create_f16_failures.php',
            sprintf(<<<'PHP'
<?php

declare(strict_types=1);

namespace %s;

use Quantum\Database\Dbal\Contract\ConnectionInterface;
use Quantum\Database\Migration\AbstractMigration;

final class CreateF16FailuresMigration extends AbstractMigration
{
    public function version(): string
    {
        return '202608220003';
    }

    public function description(): string
    {
        return 'Create failing table.';
    }

    public function up(ConnectionInterface $connection): void
    {
        throw new \RuntimeException('boom failing migration');
    }

    public function down(ConnectionInterface $connection): void
    {
        $connection->executeStatement('DROP TABLE IF EXISTS f16_failures');
    }
}
PHP
            , $namespace)
        );
    }

    private function makeTempProject(string $basePath): void
    {
        $configPath = $basePath . DIRECTORY_SEPARATOR . 'config';
        $bootstrapPath = $basePath . DIRECTORY_SEPARATOR . 'bootstrap';
        $migrationPath = $basePath . DIRECTORY_SEPARATOR . 'database' . DIRECTORY_SEPARATOR . 'migrations';
        $storagePath = $basePath . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'database';
        $sqlitePath = $storagePath . DIRECTORY_SEPARATOR . 'app.sqlite';
        $autoloadPath = getcwd() . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';
        $namespace = 'TempMigrations\\T' . substr(md5($basePath), 0, 8);

        mkdir($configPath, 0777, true);
        mkdir($bootstrapPath, 0777, true);
        mkdir($migrationPath, 0777, true);
        mkdir($storagePath, 0777, true);

        file_put_contents(
            $configPath . DIRECTORY_SEPARATOR . 'app.php',
            "<?php\n\ndeclare(strict_types=1);\n\nreturn " . var_export([
                'name' => 'VoltStack Migration Feature Test',
                'env' => 'testing',
                'debug' => true,
                'providers' => [
                    DatabaseServiceProvider::class,
                ],
            ], true) . ";\n"
        );

        file_put_contents(
            $configPath . DIRECTORY_SEPARATOR . 'database.php',
            "<?php\n\ndeclare(strict_types=1);\n\nreturn " . var_export([
                'default' => 'primary',
                'connections' => [
                    'primary' => [
                        'driver' => 'sqlite',
                        'path' => $sqlitePath,
                        'memory' => false,
                        'foreign_keys' => true,
                    ],
                ],
                'timeouts' => [
                    'soft_timeout_ms' => 30000,
                    'max_idle_ms_before_ping' => 0,
                ],
                'query_limits' => [
                    'max_rows' => 100000,
                    'max_depth' => 32,
                ],
                'security' => [
                    'redact_sensitive' => true,
                    'policies' => [
                        'soft_delete_filter' => true,
                    ],
                ],
                'cli' => [
                    'allow_raw_query' => true,
                ],
                'migrations' => [
                    'paths' => [
                        'database/migrations',
                    ],
                    'classes' => [],
                    'table' => 'framework_migrations',
                ],
            ], true) . ";\n"
        );

        $bootstrapPhp = <<<PHP
<?php

declare(strict_types=1);

require_once %s;

use Quantum\\Bootstrap\\Bootstrapper;
use VoltStack\\Framework\\Application;

\$app = new Application(%s);
\$bootstrapper = new Bootstrapper(\$app);
\$bootstrapper->loadConfiguration();

foreach ((array) \$app->config('app.providers', []) as \$provider) {
    \$app->register(\$provider);
}

\$app->boot();

return \$app;
PHP;

        file_put_contents(
            $bootstrapPath . DIRECTORY_SEPARATOR . 'app.php',
            sprintf($bootstrapPhp, var_export($autoloadPath, true), var_export($basePath, true))
        );

        file_put_contents(
            $migrationPath . DIRECTORY_SEPARATOR . '202608220001_create_f16_users.php',
            sprintf(<<<'PHP'
<?php

declare(strict_types=1);

namespace %s;

use Quantum\Database\Dbal\Contract\ConnectionInterface;
use Quantum\Database\Migration\AbstractMigration;

final class CreateF16UsersMigration extends AbstractMigration
{
    public function version(): string
    {
        return '202608220001';
    }

    public function description(): string
    {
        return 'Create f16 users table.';
    }

    public function up(ConnectionInterface $connection): void
    {
        $connection->executeStatement('CREATE TABLE IF NOT EXISTS f16_users (id INTEGER PRIMARY KEY AUTOINCREMENT, email TEXT NOT NULL)');
    }

    public function down(ConnectionInterface $connection): void
    {
        $connection->executeStatement('DROP TABLE IF EXISTS f16_users');
    }
}
PHP
            , $namespace)
        );

        file_put_contents(
            $migrationPath . DIRECTORY_SEPARATOR . '202608220002_create_f16_logs.php',
            sprintf(<<<'PHP'
<?php

declare(strict_types=1);

namespace %s;

use Quantum\Database\Dbal\Contract\ConnectionInterface;
use Quantum\Database\Migration\AbstractMigration;

final class CreateF16LogsMigration extends AbstractMigration
{
    public function version(): string
    {
        return '202608220002';
    }

    public function description(): string
    {
        return 'Create f16 logs table.';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(ConnectionInterface $connection): void
    {
        $connection->executeStatement('CREATE TABLE IF NOT EXISTS f16_logs (id INTEGER PRIMARY KEY AUTOINCREMENT, message TEXT NOT NULL)');
    }

    public function down(ConnectionInterface $connection): void
    {
        $connection->executeStatement('DROP TABLE IF EXISTS f16_logs');
    }
}
PHP
            , $namespace)
        );
    }

    private function deleteDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $items = scandir($path);
        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if (in_array($item, ['.', '..'], true)) {
                continue;
            }

            $target = $path . DIRECTORY_SEPARATOR . $item;
            if (is_dir($target)) {
                $this->deleteDirectory($target);
                continue;
            }

            @unlink($target);
        }

        @rmdir($path);
    }
}
