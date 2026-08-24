<?php

declare(strict_types=1);

namespace VoltStack\Test\Feature;

use PHPUnit\Framework\TestCase;
use Quantum\Console\ConsoleApplication;
use Quantum\Console\Output;
use Quantum\Database\DatabaseContext;
use Quantum\Database\Operation\DatabaseExecutionPolicy;
use Quantum\Database\Operation\DatabaseOperationRuntime;
use Quantum\Database\Operation\OperationKind;
use Quantum\Database\Operation\RawOperation;
use Quantum\Http\Request;
use Quantum\HttpKernel\HttpKernel;
use Quantum\Routing\Router;
use VoltStack\Framework\Application;
use VoltStack\Framework\Provider\DatabaseServiceProvider;

final class DatabaseHealthCommandTest extends TestCase
{
    private string $basePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->basePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'voltstack-db-health-command-' . bin2hex(random_bytes(6));
        $this->makeTempProject($this->basePath);
    }

    protected function tearDown(): void
    {
        $this->deleteDirectory($this->basePath);

        parent::tearDown();
    }

    public function test_cli_health_reports_persisted_snapshot_from_http_scope(): void
    {
        $app = $this->loadApp();
        $router = $app->make(Router::class);
        $router->get('/health-seed', function () use ($app): string {
            /** @var DatabaseContext $context */
            $context = $app->make(DatabaseContext::class);
            /** @var DatabaseOperationRuntime $runtime */
            $runtime = $app->make(DatabaseOperationRuntime::class);
            $policy = DatabaseExecutionPolicy::fromConfig((array) $app->config('database', []));
            $plan = $runtime->plan(
                new RawOperation(OperationKind::RawQuery, 'SELECT 1 AS one', [], 'primary'),
                $context,
                $policy,
            );
            $runtime->execute($plan, $context);

            return 'ok';
        });

        $response = $app->make(HttpKernel::class)->handle(Request::create('/health-seed'));
        self::assertSame('ok', $response->content());

        $result = $this->runConsole(['volt', 'db:health']);
        self::assertSame(0, $result['exit']);
        self::assertStringContainsString('Database health: request=', $result['stdout']);
        self::assertStringContainsString('Node: VoltStack Health Command Feature Test', $result['stdout']);
        self::assertStringContainsString('Summary: total=1 completed=1', $result['stdout']);
        self::assertStringContainsString('Health: segments=1 closed=1', $result['stdout']);
        self::assertStringContainsString('target=unknown', $result['stdout']);

        $json = $this->runConsole(['volt', 'db:health', '--json']);
        self::assertSame(0, $json['exit']);
        self::assertStringContainsString('"summary"', $json['stdout']);
        self::assertStringContainsString('"node_id": "VoltStack Health Command Feature Test"', $json['stdout']);
        self::assertStringContainsString('"total_operations": 1', $json['stdout']);
        self::assertStringContainsString('"closed_segments": 1', $json['stdout']);
    }

    public function test_cli_health_can_aggregate_recent_snapshots_from_jsonl_store(): void
    {
        $app = $this->loadApp();
        $router = $app->make(Router::class);
        $router->get('/health-seed', function () use ($app): string {
            /** @var DatabaseContext $context */
            $context = $app->make(DatabaseContext::class);
            /** @var DatabaseOperationRuntime $runtime */
            $runtime = $app->make(DatabaseOperationRuntime::class);
            $policy = DatabaseExecutionPolicy::fromConfig((array) $app->config('database', []));
            $plan = $runtime->plan(
                new RawOperation(OperationKind::RawQuery, 'SELECT 1 AS one', [], 'primary'),
                $context,
                $policy,
            );
            $runtime->execute($plan, $context);

            return 'ok';
        });

        $kernel = $app->make(HttpKernel::class);
        self::assertSame('ok', $kernel->handle(Request::create('/health-seed'))->content());
        self::assertSame('ok', $kernel->handle(Request::create('/health-seed'))->content());

        $result = $this->runConsole(['volt', 'db:health', '--aggregate', '--limit=10']);
        self::assertSame(0, $result['exit']);
        self::assertStringContainsString('Database health aggregate: snapshots=2 requests=2 tenants=0 nodes=1 segments=1', $result['stdout']);
        self::assertStringContainsString('Summary: total=2 completed=2 failed=0 cancelled=0 slow=0', $result['stdout']);
        self::assertStringContainsString('Health: closed=2 half_open=0 open=0', $result['stdout']);

        $json = $this->runConsole(['volt', 'db:health', '--aggregate', '--json', '--limit=10']);
        self::assertSame(0, $json['exit']);
        self::assertStringContainsString('"snapshots": 2', $json['stdout']);
        self::assertStringContainsString('"requests": 2', $json['stdout']);
        self::assertStringContainsString('"nodes": 1', $json['stdout']);
        self::assertStringContainsString('"total_operations": 2', $json['stdout']);
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
                'name' => 'VoltStack Health Command Feature Test',
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
                'observability' => [
                    'dispatcher' => 'null',
                ],
                'health' => [
                    'store' => 'jsonl',
                    'jsonl_path' => $basePath . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'framework' . DIRECTORY_SEPARATOR . 'database' . DIRECTORY_SEPARATOR . 'database-health.jsonl',
                ],
                'resilience' => [
                    'retry_limit' => 1,
                    'retry_backoff_ms' => 0,
                    'circuit_breaker' => [
                        'failure_threshold' => 2,
                        'cooldown_ms' => 30000,
                    ],
                ],
                'security' => [
                    'redact_sensitive' => true,
                    'policies' => [],
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
