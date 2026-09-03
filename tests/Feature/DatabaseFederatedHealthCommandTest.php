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

final class DatabaseFederatedHealthCommandTest extends TestCase
{
    private string $basePath;
    private string $sharedHealthDirectory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->basePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'voltstack-db-federated-health-' . bin2hex(random_bytes(6));
        $this->sharedHealthDirectory = $this->basePath . DIRECTORY_SEPARATOR . 'shared-health';
        mkdir($this->sharedHealthDirectory, 0777, true);

        $this->makeTempProject($this->basePath . DIRECTORY_SEPARATOR . 'node-a', 'node-a');
        $this->makeTempProject($this->basePath . DIRECTORY_SEPARATOR . 'node-b', 'node-b');
    }

    protected function tearDown(): void
    {
        $this->deleteDirectory($this->basePath);

        parent::tearDown();
    }

    public function test_cli_health_aggregates_snapshots_from_multiple_nodes_in_shared_directory(): void
    {
        $appA = $this->loadApp($this->basePath . DIRECTORY_SEPARATOR . 'node-a');
        $appB = $this->loadApp($this->basePath . DIRECTORY_SEPARATOR . 'node-b');

        $this->seedHealthSnapshot($appA, '/health-seed-a');
        $this->seedHealthSnapshot($appB, '/health-seed-b');

        $result = $this->runConsole($this->basePath . DIRECTORY_SEPARATOR . 'node-a', ['volt', 'db:health', '--aggregate', '--limit=10']);
        self::assertSame(0, $result['exit']);
        self::assertStringContainsString('Database health aggregate: snapshots=2 requests=2 tenants=0 nodes=2 segments=1', $result['stdout']);
        self::assertStringContainsString('Summary: total=2 completed=2 failed=0 cancelled=0 slow=0', $result['stdout']);
        self::assertStringContainsString('Health: closed=2 half_open=0 open=0', $result['stdout']);

        $json = $this->runConsole($this->basePath . DIRECTORY_SEPARATOR . 'node-a', ['volt', 'db:health', '--aggregate', '--json', '--limit=10']);
        self::assertSame(0, $json['exit']);
        self::assertStringContainsString('"snapshots": 2', $json['stdout']);
        self::assertStringContainsString('"nodes": 2', $json['stdout']);
        self::assertStringContainsString('"total_operations": 2', $json['stdout']);
        self::assertStringContainsString('"resource_governance"', $json['stdout']);
        self::assertStringContainsString('"view_hints"', $json['stdout']);
        self::assertStringContainsString('"summary"', $json['stdout']);
        self::assertStringContainsString('"top_offenders"', $json['stdout']);
        self::assertStringContainsString('"signal_coverage"', $json['stdout']);
        self::assertStringContainsString('"alert_sampling_detail": false', $json['stdout']);
        self::assertStringContainsString('"resource_governance_summary": true', $json['stdout']);
        self::assertStringContainsString('"presets"', $json['stdout']);
        self::assertStringContainsString('"recommended_presets"', $json['stdout']);
    }

    public function test_health_snapshot_persists_remote_challenge_cluster_advertisement(): void
    {
        $appA = $this->loadApp($this->basePath . DIRECTORY_SEPARATOR . 'node-a');

        $this->seedHealthSnapshot($appA, '/health-seed-advertisement');

        $result = $this->runConsole($this->basePath . DIRECTORY_SEPARATOR . 'node-a', ['volt', 'db:health', '--json']);
        self::assertSame(0, $result['exit']);
        self::assertStringContainsString('"cluster_advertisement"', $result['stdout']);
        self::assertStringContainsString('"endpoint": "http://node-a.cluster.internal/_volt/db/remote-replay/challenge"', $result['stdout']);
        self::assertStringContainsString('"source": "app_url"', $result['stdout']);
    }

    private function seedHealthSnapshot(Application $app, string $route): void
    {
        $router = $app->make(Router::class);
        $router->get($route, function () use ($app): string {
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

        $response = $app->make(HttpKernel::class)->handle(Request::create($route));
        self::assertSame('ok', $response->content());
    }

    /**
     * @param array<int, string> $argv
     * @return array{exit:int,stdout:string,stderr:string}
     */
    private function runConsole(string $projectPath, array $argv): array
    {
        $output = new Output();
        $console = new ConsoleApplication($projectPath, output: $output);

        return [
            'exit' => $console->run($argv),
            'stdout' => $output->stdout(),
            'stderr' => $output->stderr(),
        ];
    }

    private function loadApp(string $projectPath): Application
    {
        /** @var Application $app */
        $app = require $projectPath . DIRECTORY_SEPARATOR . 'bootstrap' . DIRECTORY_SEPARATOR . 'app.php';

        return $app;
    }

    private function makeTempProject(string $basePath, string $nodeId): void
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
                'name' => 'VoltStack Federated Health Feature Test',
                'env' => 'testing',
                'debug' => true,
                'url' => 'http://' . $nodeId . '.cluster.internal',
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
                    'store' => 'directory',
                    'directory_path' => $this->sharedHealthDirectory,
                    'node_id' => $nodeId,
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
