<?php

declare(strict_types=1);

namespace VoltStack\Test\Feature;

use PHPUnit\Framework\TestCase;
use Quantum\Console\ConsoleApplication;
use Quantum\Console\Output;
use Quantum\Database\Dbal\Contract\ConnectionInterface;
use VoltStack\Framework\Application;
use VoltStack\Framework\Provider\DatabaseServiceProvider;

final class DatabaseSeederCommandTest extends TestCase
{
    private string $basePath;

    protected function tearDown(): void
    {
        if (isset($this->basePath) && is_dir($this->basePath)) {
            $this->deleteDirectory($this->basePath);
        }

        parent::tearDown();
    }

    public function test_cli_seed_runs_discovered_seeders_and_resolves_references(): void
    {
        $this->basePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'voltstack-db-seeders-' . bin2hex(random_bytes(6));
        $this->makeTempProject($this->basePath, 'testing');

        $app = $this->loadApp();
        $this->createTables($app);

        $status = $this->runConsole(['volt', 'db:seed-status']);
        self::assertSame(0, $status['exit']);
        self::assertStringContainsString('base-users', $status['stdout']);
        self::assertStringContainsString('demo-posts', $status['stdout']);

        $seed = $this->runConsole(['volt', 'db:seed']);
        self::assertSame(0, $seed['exit']);
        self::assertStringContainsString('Seeders ejecutados: 2', $seed['stdout']);

        self::assertSame(1, $this->countRows($app, 'f17_users'));
        self::assertSame(1, $this->countRows($app, 'f17_posts'));
        self::assertSame('admin@voltstack.dev', $this->firstValue($app, 'SELECT email FROM f17_users LIMIT 1'));
        self::assertSame('hello-seeded-world', $this->firstValue($app, 'SELECT slug FROM f17_posts LIMIT 1'));
    }

    public function test_cli_seed_pretend_does_not_change_database_state(): void
    {
        $this->basePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'voltstack-db-seeders-' . bin2hex(random_bytes(6));
        $this->makeTempProject($this->basePath, 'testing');

        $app = $this->loadApp();
        $this->createTables($app);

        $pretend = $this->runConsole(['volt', 'db:seed', '--pretend']);
        self::assertSame(0, $pretend['exit']);
        self::assertStringContainsString('Plan de seed: 2 seeder(s).', $pretend['stdout']);

        self::assertSame(0, $this->countRows($app, 'f17_users'));
        self::assertSame(0, $this->countRows($app, 'f17_posts'));
    }

    public function test_cli_seed_requires_force_in_production(): void
    {
        $this->basePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'voltstack-db-seeders-' . bin2hex(random_bytes(6));
        $this->makeTempProject($this->basePath, 'production');

        $app = $this->loadApp();
        $this->createTables($app);

        $denied = $this->runConsole(['volt', 'db:seed']);
        self::assertSame(1, $denied['exit']);
        self::assertStringContainsString('--force', $denied['stderr']);
        self::assertSame(0, $this->countRows($app, 'f17_users'));

        $allowed = $this->runConsole(['volt', 'db:seed', '--force']);
        self::assertSame(0, $allowed['exit']);
        self::assertSame(1, $this->countRows($app, 'f17_users'));
        self::assertSame(1, $this->countRows($app, 'f17_posts'));
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
        $connection->executeStatement('CREATE TABLE IF NOT EXISTS f17_users (id INTEGER PRIMARY KEY AUTOINCREMENT, email TEXT NOT NULL)');
        $connection->executeStatement('CREATE TABLE IF NOT EXISTS f17_posts (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, slug TEXT NOT NULL)');
    }

    private function countRows(Application $app, string $table): int
    {
        $row = $app->make(ConnectionInterface::class)
            ->executeQuery(sprintf('SELECT COUNT(*) AS c FROM %s', $table))
            ->fetchOneAssoc();

        return (int) ($row['c'] ?? 0);
    }

    private function firstValue(Application $app, string $sql): ?string
    {
        $row = $app->make(ConnectionInterface::class)->executeQuery($sql)->fetchOneAssoc();
        if (!is_array($row)) {
            return null;
        }

        return (string) array_values($row)[0];
    }

    private function makeTempProject(string $basePath, string $env): void
    {
        $configPath = $basePath . DIRECTORY_SEPARATOR . 'config';
        $bootstrapPath = $basePath . DIRECTORY_SEPARATOR . 'bootstrap';
        $seederPath = $basePath . DIRECTORY_SEPARATOR . 'database' . DIRECTORY_SEPARATOR . 'seeders';
        $storagePath = $basePath . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'database';
        $sqlitePath = $storagePath . DIRECTORY_SEPARATOR . 'app.sqlite';
        $autoloadPath = getcwd() . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';
        $namespace = 'TempSeeders\\T' . substr(md5($basePath), 0, 8);

        mkdir($configPath, 0777, true);
        mkdir($bootstrapPath, 0777, true);
        mkdir($seederPath, 0777, true);
        mkdir($storagePath, 0777, true);

        file_put_contents(
            $configPath . DIRECTORY_SEPARATOR . 'app.php',
            "<?php\n\ndeclare(strict_types=1);\n\nreturn " . var_export([
                'name' => 'VoltStack Seeder Feature Test',
                'env' => $env,
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
                'seeders' => [
                    'paths' => [
                        'database/seeders',
                    ],
                    'classes' => [],
                    'require_force_in_production' => true,
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
            $seederPath . DIRECTORY_SEPARATOR . '001_base_users.php',
            sprintf(<<<'PHP'
<?php

declare(strict_types=1);

namespace %s;

use Quantum\Database\Seeder\AbstractSeeder;
use Quantum\Database\Seeder\SeedExecutionContext;

final class BaseUsersSeeder extends AbstractSeeder
{
    public function name(): string
    {
        return 'base-users';
    }

    public function description(): string
    {
        return 'Inserta el usuario base y guarda su referencia.';
    }

    public function run(SeedExecutionContext $context): void
    {
        $db = $context->connection();
        $db->executeStatement('INSERT INTO f17_users (email) VALUES (?)', ['admin@voltstack.dev']);
        $context->references()->set('user.admin_id', (int) $db->lastInsertId());
    }
}
PHP
            , $namespace)
        );

        file_put_contents(
            $seederPath . DIRECTORY_SEPARATOR . '002_demo_posts.php',
            sprintf(<<<'PHP'
<?php

declare(strict_types=1);

namespace %s;

use Quantum\Database\Seeder\AbstractSeeder;
use Quantum\Database\Seeder\SeedExecutionContext;

final class DemoPostsSeeder extends AbstractSeeder
{
    public function name(): string
    {
        return 'demo-posts';
    }

    public function description(): string
    {
        return 'Usa referencias del seeder anterior para crear datos relacionados.';
    }

    public function run(SeedExecutionContext $context): void
    {
        $userId = (int) $context->references()->require('user.admin_id');
        $context->connection()->executeStatement(
            'INSERT INTO f17_posts (user_id, slug) VALUES (?, ?)',
            [$userId, 'hello-seeded-world'],
        );
    }
}
PHP
            , $namespace)
        );
    }

    private function deleteDirectory(string $path): void
    {
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
