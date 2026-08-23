<?php

declare(strict_types=1);

namespace VoltStack\Test\Feature;

use PHPUnit\Framework\TestCase;
use Quantum\Console\ConsoleApplication;
use Quantum\Console\Output;
use Quantum\Database\Dbal\Contract\ConnectionInterface;
use Quantum\Database\Schema\SchemaManager;
use VoltStack\Framework\Application;
use VoltStack\Framework\Provider\DatabaseServiceProvider;

final class DatabaseSchemaCommandTest extends TestCase
{
    private string $basePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->basePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'voltstack-db-schema-' . bin2hex(random_bytes(6));
        $this->makeTempProject($this->basePath);
    }

    protected function tearDown(): void
    {
        $this->deleteDirectory($this->basePath);

        parent::tearDown();
    }

    public function test_schema_manager_builds_snapshot_for_live_sqlite_schema(): void
    {
        $app = $this->loadApp();
        $this->createTables($app);

        /** @var SchemaManager $schema */
        $schema = $app->make(SchemaManager::class);
        $snapshot = $schema->snapshot();

        self::assertSame('sqlite', $snapshot->driver);
        self::assertTrue($schema->tableExists('f19_users'));
        self::assertSame(['f19_logs', 'f19_users'], $schema->listTables());

        $users = $schema->describeTable('f19_users');
        self::assertSame(['id'], $users->primaryKey);
        self::assertCount(4, $users->columns);
        self::assertSame('INTEGER', $users->column('id')?->nativeType);
        self::assertTrue($users->column('id')?->autoIncrement ?? false);
        self::assertFalse($users->column('email')?->nullable ?? true);
        self::assertSame("'draft'", $users->column('status')?->defaultValue);
    }

    public function test_cli_schema_status_lists_tables_and_supports_json_snapshot(): void
    {
        $app = $this->loadApp();
        $this->createTables($app);

        $status = $this->runConsole(['volt', 'db:schema-status']);
        $json = $this->runConsole(['volt', 'db:schema-status', '--json']);

        self::assertSame(0, $status['exit']);
        self::assertStringContainsString('f19_users', $status['stdout']);
        self::assertStringContainsString('f19_logs', $status['stdout']);

        self::assertSame(0, $json['exit']);
        self::assertStringContainsString('"driver": "sqlite"', $json['stdout']);
        self::assertStringContainsString('"name": "f19_users"', $json['stdout']);
    }

    public function test_cli_schema_describe_renders_columns_and_reports_missing_table(): void
    {
        $app = $this->loadApp();
        $this->createTables($app);

        $describe = $this->runConsole(['volt', 'db:schema-describe', 'f19_users']);
        $missing = $this->runConsole(['volt', 'db:schema-describe', 'missing_table']);

        self::assertSame(0, $describe['exit']);
        self::assertStringContainsString('Table: f19_users', $describe['stdout']);
        self::assertStringContainsString('email type=TEXT nullable=no', $describe['stdout']);
        self::assertStringContainsString('status type=TEXT nullable=no', $describe['stdout']);

        self::assertSame(1, $missing['exit']);
        self::assertStringContainsString('missing_table', $missing['stderr']);
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

    private function createTables(Application $app): void
    {
        $connection = $app->make(ConnectionInterface::class);
        $connection->executeStatement(
            "CREATE TABLE IF NOT EXISTS f19_users (id INTEGER PRIMARY KEY AUTOINCREMENT, email TEXT NOT NULL, age INTEGER NULL, status TEXT NOT NULL DEFAULT 'draft')"
        );
        $connection->executeStatement(
            'CREATE TABLE IF NOT EXISTS f19_logs (id INTEGER PRIMARY KEY AUTOINCREMENT, message TEXT NOT NULL)'
        );
    }

    private function makeTempProject(string $basePath): void
    {
        $configPath = $basePath . DIRECTORY_SEPARATOR . 'config';
        $bootstrapPath = $basePath . DIRECTORY_SEPARATOR . 'bootstrap';
        $storagePath = $basePath . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'database';
        $sqlitePath = $storagePath . DIRECTORY_SEPARATOR . 'app.sqlite';
        $autoloadPath = getcwd() . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';

        mkdir($configPath, 0777, true);
        mkdir($bootstrapPath, 0777, true);
        mkdir($storagePath, 0777, true);

        file_put_contents(
            $configPath . DIRECTORY_SEPARATOR . 'app.php',
            "<?php\n\ndeclare(strict_types=1);\n\nreturn " . var_export([
                'name' => 'VoltStack Schema Feature Test',
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
                'schema' => [
                    'enabled' => true,
                    'strict' => true,
                    'cache' => [
                        'enabled' => false,
                        'version' => 1,
                    ],
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
