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

final class DatabaseFederatedIdempotencyCommandTest extends TestCase
{
    private string $basePath;
    private string $sharedIdempotencyDirectory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->basePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'voltstack-db-federated-idempotency-' . bin2hex(random_bytes(6));
        $this->sharedIdempotencyDirectory = $this->basePath . DIRECTORY_SEPARATOR . 'shared-idempotency';
        mkdir($this->sharedIdempotencyDirectory, 0777, true);

        $this->makeTempProject($this->basePath . DIRECTORY_SEPARATOR . 'node-a', 'node-a');
        $this->makeTempProject($this->basePath . DIRECTORY_SEPARATOR . 'node-b', 'node-b');
    }

    protected function tearDown(): void
    {
        $this->deleteDirectory($this->basePath);

        parent::tearDown();
    }

    public function test_cli_idempotency_aggregates_records_from_multiple_nodes_in_shared_directory(): void
    {
        $appA = $this->loadApp($this->basePath . DIRECTORY_SEPARATOR . 'node-a');
        $appB = $this->loadApp($this->basePath . DIRECTORY_SEPARATOR . 'node-b');

        $this->seedCompletedRecord($appA, 'node-a', 'mutation-users-node-a', 'req-node-a', 'users', false);
        $this->seedCompletedRecord($appB, 'node-b', 'mutation-users-node-b', 'req-node-b', 'users', true);

        $result = $this->runConsole($this->basePath . DIRECTORY_SEPARATOR . 'node-a', ['volt', 'db:idempotency', '--aggregate', '--limit=10']);
        self::assertSame(0, $result['exit']);
        self::assertStringContainsString('Database idempotency aggregate: records=2 requests=2 connections=1 targets=1 nodes=2', $result['stdout']);
        self::assertStringContainsString('Replay support: persisted_summary=1 legacy_reconstructed=1 warning_candidates=1', $result['stdout']);
        self::assertStringContainsString(
            'Perspective: current_node=node-a local_records=1 remote_records=1 unknown_records=0',
            $result['stdout']
        );
        self::assertStringContainsString(
            'Node: node-a perspective=local_node records=1 completed=1 failed=0 pending=0 persisted_summary=1 legacy_reconstructed=0 warning_candidates=0',
            $result['stdout']
        );
        self::assertStringContainsString(
            'Node: node-b perspective=federated_remote_node records=1 completed=1 failed=0 pending=0 persisted_summary=0 legacy_reconstructed=1 warning_candidates=1',
            $result['stdout']
        );

        $json = $this->runConsole($this->basePath . DIRECTORY_SEPARATOR . 'node-a', ['volt', 'db:idempotency', '--aggregate', '--json', '--limit=10']);
        self::assertSame(0, $json['exit']);
        self::assertStringContainsString('"current_node_id": "node-a"', $json['stdout']);
        self::assertStringContainsString('"local_records": 1', $json['stdout']);
        self::assertStringContainsString('"remote_records": 1', $json['stdout']);
        self::assertStringContainsString('"nodes": 2', $json['stdout']);
        self::assertStringContainsString('"nodes_detail"', $json['stdout']);
        self::assertStringContainsString('"node_id": "node-a"', $json['stdout']);
        self::assertStringContainsString('"node_id": "node-b"', $json['stdout']);
        self::assertStringContainsString('"legacy_replay_warning_candidates": 1', $json['stdout']);
    }

    private function seedCompletedRecord(
        Application $app,
        string $nodeId,
        string $rawKey,
        string $requestId,
        string $target,
        bool $legacy,
    ): void {
        /** @var DatabaseIdempotencyStoreInterface $store */
        $store = $app->make(DatabaseIdempotencyStoreInterface::class);
        $record = new DatabaseIdempotencyRecord(
            keyHash: hash('sha256', $rawKey),
            operationFingerprint: 'plan-' . $rawKey,
            requestId: $requestId,
            connectionName: 'primary',
            logicalTarget: $target,
            createdAt: $legacy ? '2026-08-25T09:01:00+00:00' : '2026-08-25T09:00:00+00:00',
            nodeId: $nodeId,
            status: 'pending',
        );
        $store->acquire($record);
        $store->complete($record, $legacy
            ? [
                'kind' => 'raw_execute',
                'affected_rows' => 1,
                'rows_read' => 0,
                'outcome' => 'completed',
                'confirmed_at' => '2026-08-25T09:01:10+00:00',
            ]
            : [
                'kind' => 'raw_execute',
                'affected_rows' => 1,
                'rows_read' => 0,
                'outcome' => 'completed',
                'confirmed_at' => '2026-08-25T09:00:10+00:00',
                'summary_version' => 1,
                'replay_reproducibility' => 'persisted_summary',
                'source_node_id' => $nodeId,
                'evidence_version' => 1,
                'evidence_mode' => 'persisted_evidence',
                'confirmation_fingerprint' => hash('sha256', $requestId . '-confirmation'),
                'result_summary' => [
                    'kind' => 'raw_execute',
                    'is_select' => false,
                    'affected_rows' => 1,
                    'rows_read' => 0,
                    'column_count' => 0,
                    'result_type' => 'success_no_rows',
                ],
            ]);
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
                'name' => 'VoltStack Federated Idempotency Feature Test',
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
                    'directory_path' => $this->sharedIdempotencyDirectory,
                    'node_id' => $nodeId,
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