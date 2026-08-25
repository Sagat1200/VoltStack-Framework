<?php

declare(strict_types=1);

namespace VoltStack\Test\Feature;

use PHPUnit\Framework\TestCase;
use Quantum\Console\ConsoleApplication;
use Quantum\Console\Output;
use Quantum\Database\Operation\Contracts\DatabaseIdempotencyStoreInterface;
use Quantum\Database\Operation\DatabaseIdempotencyRecord;
use VoltStack\Framework\Application;
use VoltStack\Framework\Provider\DatabaseServiceProvider;

final class DatabaseIdempotencyCommandTest extends TestCase
{
    private string $basePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->basePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'voltstack-db-idempotency-command-' . bin2hex(random_bytes(6));
        $this->makeTempProject($this->basePath);
    }

    protected function tearDown(): void
    {
        $this->deleteDirectory($this->basePath);

        parent::tearDown();
    }

    public function test_cli_idempotency_reports_latest_and_lookup_by_key(): void
    {
        $app = $this->loadApp();
        /** @var DatabaseIdempotencyStoreInterface $store */
        $store = $app->make(DatabaseIdempotencyStoreInterface::class);
        $record = new DatabaseIdempotencyRecord(
            keyHash: hash('sha256', 'mutation-users-1'),
            operationFingerprint: 'plan-users-1',
            requestId: 'req-users-1',
            connectionName: 'primary',
            logicalTarget: 'users',
            createdAt: '2026-08-25T07:00:00+00:00',
            nodeId: 'VoltStack Idempotency Command Feature Test',
            status: 'pending',
        );
        $store->acquire($record);
        $store->complete($record, [
            'kind' => 'raw_execute',
            'affected_rows' => 1,
            'rows_read' => 0,
            'outcome' => 'completed',
            'confirmed_at' => '2026-08-25T07:00:10+00:00',
        ]);

        $result = $this->runConsole(['volt', 'db:idempotency']);
        self::assertSame(0, $result['exit']);
        self::assertStringContainsString('Database idempotency: request=req-users-1 status=completed', $result['stdout']);
        self::assertStringContainsString('expires_at=n/a expired=no', $result['stdout']);
        self::assertStringContainsString('Operation: fingerprint=plan-users-1 connection=primary target=users', $result['stdout']);
        self::assertStringContainsString('Confirmation: kind=raw_execute affected_rows=1 rows_read=0 outcome=completed confirmed_at=2026-08-25T07:00:10+00:00', $result['stdout']);

        $lookup = $this->runConsole(['volt', 'db:idempotency', '--key=mutation-users-1', '--json']);
        self::assertSame(0, $lookup['exit']);
        self::assertStringContainsString('"request_id": "req-users-1"', $lookup['stdout']);
        self::assertStringContainsString('"status": "completed"', $lookup['stdout']);
        self::assertStringContainsString('"confirmation"', $lookup['stdout']);
    }

    public function test_cli_idempotency_can_aggregate_recent_records(): void
    {
        $app = $this->loadApp();
        /** @var DatabaseIdempotencyStoreInterface $store */
        $store = $app->make(DatabaseIdempotencyStoreInterface::class);

        $first = new DatabaseIdempotencyRecord(
            keyHash: hash('sha256', 'mutation-users-1'),
            operationFingerprint: 'plan-users-1',
            requestId: 'req-users-1',
            connectionName: 'primary',
            logicalTarget: 'users',
            createdAt: '2026-08-25T07:00:00+00:00',
            nodeId: 'node-a',
            status: 'pending',
        );
        $second = new DatabaseIdempotencyRecord(
            keyHash: hash('sha256', 'mutation-posts-1'),
            operationFingerprint: 'plan-posts-1',
            requestId: 'req-posts-1',
            connectionName: 'primary',
            logicalTarget: 'posts',
            createdAt: '2026-08-25T07:01:00+00:00',
            nodeId: 'node-b',
            status: 'pending',
        );

        $store->acquire($first);
        $store->complete($first);
        $store->acquire($second);
        $store->fail($second);
        $store->acquire(new DatabaseIdempotencyRecord(
            keyHash: hash('sha256', 'mutation-stale-1'),
            operationFingerprint: 'plan-stale-1',
            requestId: 'req-stale-1',
            connectionName: 'primary',
            logicalTarget: 'comments',
            createdAt: '2026-08-24T07:00:00+00:00',
            nodeId: 'node-c',
            status: 'pending',
            expiresAt: '2026-08-24T07:05:00+00:00',
        ));

        $result = $this->runConsole(['volt', 'db:idempotency', '--aggregate', '--limit=10']);
        self::assertSame(0, $result['exit']);
        self::assertStringContainsString('Database idempotency aggregate: records=3 requests=3 connections=1 targets=3 nodes=3', $result['stdout']);
        self::assertStringContainsString('Statuses: pending=1 completed=1 failed=1 expired_pending=1', $result['stdout']);

        $json = $this->runConsole(['volt', 'db:idempotency', '--aggregate', '--json', '--limit=10']);
        self::assertSame(0, $json['exit']);
        self::assertStringContainsString('"records": 3', $json['stdout']);
        self::assertStringContainsString('"completed": 1', $json['stdout']);
        self::assertStringContainsString('"failed": 1', $json['stdout']);
        self::assertStringContainsString('"expired_pending": 1', $json['stdout']);
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
                'name' => 'VoltStack Idempotency Command Feature Test',
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
                'idempotency' => [
                    'store' => 'directory',
                    'directory_path' => $basePath . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'framework' . DIRECTORY_SEPARATOR . 'database' . DIRECTORY_SEPARATOR . 'idempotency',
                    'pending_ttl_seconds' => 300,
                ],
                'observability' => [
                    'dispatcher' => 'null',
                ],
                'health' => [
                    'store' => 'null',
                ],
                'resilience' => [
                    'retry_limit' => 1,
                    'retry_backoff_ms' => 0,
                    'retry_mutations_when_idempotent' => true,
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
