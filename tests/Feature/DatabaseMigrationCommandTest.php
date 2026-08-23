<?php

declare(strict_types=1);

namespace VoltStack\Test\Feature;

use PHPUnit\Framework\TestCase;
use Quantum\Console\ConsoleApplication;
use Quantum\Console\Output;
use Quantum\Database\Dbal\Contract\ConnectionInterface;
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
        self::assertStringContainsString('Migraciones aplicadas: 1', $migrate['stdout']);
        self::assertStringContainsString('202608220001', $migrate['stdout']);

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
        self::assertStringContainsString('202608220001', $pretend['stdout']);
        self::assertStringContainsString('202608220002', $pretend['stdout']);

        $app = $this->loadApp();
        self::assertFalse($this->tableExists($app, 'f16_users'));
        self::assertFalse($this->tableExists($app, 'f16_logs'));
    }

    public function test_cli_rollback_reverts_latest_batch(): void
    {
        $migrate = $this->runConsole(['volt', 'db:migrate']);
        self::assertSame(0, $migrate['exit']);

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