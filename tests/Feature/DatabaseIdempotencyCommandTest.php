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
            'summary_version' => 1,
            'replay_reproducibility' => 'persisted_summary',
            'source_node_id' => 'VoltStack Idempotency Command Feature Test',
            'evidence_version' => 1,
            'evidence_mode' => 'persisted_evidence',
            'confirmation_fingerprint' => $confirmationFingerprint = $this->computeConfirmationFingerprint(
                $record,
                'VoltStack Idempotency Command Feature Test',
                [
                    'kind' => 'raw_execute',
                    'affected_rows' => 1,
                    'rows_read' => 0,
                    'outcome' => 'completed',
                    'confirmed_at' => '2026-08-25T07:00:10+00:00',
                    'replay_reproducibility' => 'persisted_summary',
                    'result_summary' => [
                        'kind' => 'raw_execute',
                        'is_select' => false,
                        'affected_rows' => 1,
                        'rows_read' => 0,
                        'column_count' => 0,
                        'result_type' => 'success_no_rows',
                    ],
                ],
            ),
            'attestation_version' => 1,
            'attestation_mode' => 'source_node_self_attested',
            'attested_by_node_id' => 'VoltStack Idempotency Command Feature Test',
            'attested_at' => '2026-08-25T07:00:10+00:00',
            'attestation_fingerprint' => $this->computeAttestationFingerprint(
                $record,
                'VoltStack Idempotency Command Feature Test',
                $confirmationFingerprint,
                'source_node_self_attested',
                'VoltStack Idempotency Command Feature Test',
                '2026-08-25T07:00:10+00:00',
            ),
            'result_summary' => [
                'kind' => 'raw_execute',
                'is_select' => false,
                'affected_rows' => 1,
                'rows_read' => 0,
                'column_count' => 0,
                'result_type' => 'success_no_rows',
            ],
        ]);

        $result = $this->runConsole(['volt', 'db:idempotency']);
        self::assertSame(0, $result['exit']);
        self::assertStringContainsString('Database idempotency: request=req-users-1 status=completed', $result['stdout']);
        self::assertStringContainsString('expires_at=n/a expired=no', $result['stdout']);
        self::assertStringContainsString('Operation: fingerprint=plan-users-1 connection=primary target=users', $result['stdout']);
        self::assertStringContainsString(
            'Replay origin: perspective=local_node current_node=VoltStack Idempotency Command Feature Test source_node=VoltStack Idempotency Command Feature Test',
            $result['stdout']
        );
        self::assertStringContainsString('Confirmation: kind=raw_execute affected_rows=1 rows_read=0 outcome=completed confirmed_at=2026-08-25T07:00:10+00:00', $result['stdout']);
        self::assertStringContainsString('Replay support: reproducibility=persisted_summary summary_version=1', $result['stdout']);
        self::assertStringContainsString(
            'Replay evidence: source_node=VoltStack Idempotency Command Feature Test',
            $result['stdout']
        );
        self::assertStringContainsString('evidence_version=1 mode=persisted_evidence', $result['stdout']);
        self::assertStringContainsString('Replay verification: status=verified_persisted_evidence', $result['stdout']);
        self::assertStringContainsString('Replay attestation: status=verified_source_node_attestation', $result['stdout']);
        self::assertStringContainsString('Replay trust: level=local_verified_persisted', $result['stdout']);
        self::assertStringContainsString('Result summary: type=success_no_rows is_select=no affected_rows=1 rows_read=0 column_count=0', $result['stdout']);

        $lookup = $this->runConsole(['volt', 'db:idempotency', '--key=mutation-users-1', '--json']);
        self::assertSame(0, $lookup['exit']);
        self::assertStringContainsString('"request_id": "req-users-1"', $lookup['stdout']);
        self::assertStringContainsString('"status": "completed"', $lookup['stdout']);
        self::assertStringContainsString('"confirmation"', $lookup['stdout']);
        self::assertStringContainsString('"result_summary"', $lookup['stdout']);
        self::assertStringContainsString('"replay_reproducibility": "persisted_summary"', $lookup['stdout']);
        self::assertStringContainsString('"replay_origin": "local_node"', $lookup['stdout']);
        self::assertStringContainsString('"confirmation_evidence"', $lookup['stdout']);
        self::assertStringContainsString('"evidence_mode": "persisted_evidence"', $lookup['stdout']);
        self::assertStringContainsString('"verification_status": "verified_persisted_evidence"', $lookup['stdout']);
        self::assertStringContainsString('"attestation_verification_status": "verified_source_node_attestation"', $lookup['stdout']);
        self::assertStringContainsString('"trust_level": "local_verified_persisted"', $lookup['stdout']);
        self::assertStringContainsString('"evidence_trust_level": "local_verified_persisted"', $lookup['stdout']);
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
        $third = new DatabaseIdempotencyRecord(
            keyHash: hash('sha256', 'mutation-legacy-1'),
            operationFingerprint: 'plan-legacy-1',
            requestId: 'req-legacy-1',
            connectionName: 'primary',
            logicalTarget: 'comments',
            createdAt: '2026-08-25T07:02:00+00:00',
            nodeId: 'node-c',
            status: 'pending',
        );

        $store->acquire($first);
        $store->complete($first, [
            'kind' => 'raw_execute',
            'affected_rows' => 1,
            'rows_read' => 0,
            'outcome' => 'completed',
            'confirmed_at' => '2026-08-25T07:00:10+00:00',
            'summary_version' => 1,
            'replay_reproducibility' => 'persisted_summary',
            'source_node_id' => 'node-a',
            'evidence_version' => 1,
            'evidence_mode' => 'persisted_evidence',
            'confirmation_fingerprint' => $firstConfirmationFingerprint = $this->computeConfirmationFingerprint(
                $first,
                'node-a',
                [
                    'kind' => 'raw_execute',
                    'affected_rows' => 1,
                    'rows_read' => 0,
                    'outcome' => 'completed',
                    'confirmed_at' => '2026-08-25T07:00:10+00:00',
                    'replay_reproducibility' => 'persisted_summary',
                    'result_summary' => [
                        'kind' => 'raw_execute',
                        'is_select' => false,
                        'affected_rows' => 1,
                        'rows_read' => 0,
                        'column_count' => 0,
                        'result_type' => 'success_no_rows',
                    ],
                ],
            ),
            'attestation_version' => 1,
            'attestation_mode' => 'source_node_self_attested',
            'attested_by_node_id' => 'node-a',
            'attested_at' => '2026-08-25T07:00:10+00:00',
            'attestation_fingerprint' => $this->computeAttestationFingerprint(
                $first,
                'node-a',
                $firstConfirmationFingerprint,
                'source_node_self_attested',
                'node-a',
                '2026-08-25T07:00:10+00:00',
            ),
            'result_summary' => [
                'kind' => 'raw_execute',
                'is_select' => false,
                'affected_rows' => 1,
                'rows_read' => 0,
                'column_count' => 0,
                'result_type' => 'success_no_rows',
            ],
        ]);
        $store->acquire($second);
        $store->fail($second);
        $store->acquire($third);
        $store->complete($third, [
            'kind' => 'raw_execute',
            'affected_rows' => 2,
            'rows_read' => 0,
            'outcome' => 'completed',
            'confirmed_at' => '2026-08-25T07:02:10+00:00',
        ]);
        $store->acquire(new DatabaseIdempotencyRecord(
            keyHash: hash('sha256', 'mutation-stale-1'),
            operationFingerprint: 'plan-stale-1',
            requestId: 'req-stale-1',
            connectionName: 'primary',
            logicalTarget: 'likes',
            createdAt: '2026-08-24T07:00:00+00:00',
            nodeId: 'node-d',
            status: 'pending',
            expiresAt: '2026-08-24T07:05:00+00:00',
        ));

        $result = $this->runConsole(['volt', 'db:idempotency', '--aggregate', '--limit=10']);
        self::assertSame(0, $result['exit']);
        self::assertStringContainsString('Database idempotency aggregate: records=4 requests=4 connections=1 targets=4 nodes=4', $result['stdout']);
        self::assertStringContainsString('Statuses: pending=1 completed=2 failed=1 expired_pending=1', $result['stdout']);
        self::assertStringContainsString('Confirmations: with_confirmation=2 without_confirmation=2 summary_version_1=1 legacy_without_summary=1', $result['stdout']);
        self::assertStringContainsString('Replay support: persisted_summary=1 legacy_reconstructed=1 warning_candidates=1', $result['stdout']);
        self::assertStringContainsString('Verification: verified=1 reconstructed_legacy=1 mismatch=0', $result['stdout']);
        self::assertStringContainsString('Attestation: verified=1 missing=0 legacy=1 mismatch=0', $result['stdout']);
        self::assertStringContainsString(
            'Perspective: current_node=VoltStack Idempotency Command Feature Test local_records=0 remote_records=4 unknown_records=0',
            $result['stdout']
        );
        self::assertStringContainsString('Trust: local_verified=0 remote_attested=1 remote_verified=0 legacy_reconstructed=1 untrusted_mismatch=0 untrusted_attestation=0 unknown=0', $result['stdout']);

        $json = $this->runConsole(['volt', 'db:idempotency', '--aggregate', '--json', '--limit=10']);
        self::assertSame(0, $json['exit']);
        self::assertStringContainsString('"records": 4', $json['stdout']);
        self::assertStringContainsString('"completed": 2', $json['stdout']);
        self::assertStringContainsString('"failed": 1', $json['stdout']);
        self::assertStringContainsString('"expired_pending": 1', $json['stdout']);
        self::assertStringContainsString('"with_confirmation": 2', $json['stdout']);
        self::assertStringContainsString('"legacy_without_summary": 1', $json['stdout']);
        self::assertStringContainsString('"persisted_summary": 1', $json['stdout']);
        self::assertStringContainsString('"legacy_reconstructed": 1', $json['stdout']);
        self::assertStringContainsString('"legacy_replay_warning_candidates": 1', $json['stdout']);
        self::assertStringContainsString('"current_node_id": "VoltStack Idempotency Command Feature Test"', $json['stdout']);
        self::assertStringContainsString('"verified_persisted_evidence": 1', $json['stdout']);
        self::assertStringContainsString('"verified_source_node_attestation": 1', $json['stdout']);
        self::assertStringContainsString('"remote_attested_persisted": 1', $json['stdout']);
    }

    public function test_cli_idempotency_reconstructs_replay_support_for_legacy_confirmation(): void
    {
        $app = $this->loadApp();
        /** @var DatabaseIdempotencyStoreInterface $store */
        $store = $app->make(DatabaseIdempotencyStoreInterface::class);
        $record = new DatabaseIdempotencyRecord(
            keyHash: hash('sha256', 'mutation-users-legacy'),
            operationFingerprint: 'plan-users-legacy',
            requestId: 'req-users-legacy',
            connectionName: 'primary',
            logicalTarget: 'users',
            createdAt: '2026-08-25T08:00:00+00:00',
            nodeId: 'node-legacy',
            status: 'pending',
        );
        $store->acquire($record);
        $store->complete($record, [
            'kind' => 'raw_execute',
            'affected_rows' => 2,
            'rows_read' => 0,
            'outcome' => 'completed',
            'confirmed_at' => '2026-08-25T08:00:10+00:00',
        ]);

        $result = $this->runConsole(['volt', 'db:idempotency', '--key=mutation-users-legacy']);

        self::assertSame(0, $result['exit']);
        self::assertStringContainsString(
            'Replay origin: perspective=federated_remote_node current_node=VoltStack Idempotency Command Feature Test source_node=node-legacy',
            $result['stdout']
        );
        self::assertStringContainsString('Replay support: reproducibility=legacy_reconstructed summary_version=n/a', $result['stdout']);
        self::assertStringContainsString('Replay evidence: source_node=node-legacy', $result['stdout']);
        self::assertStringContainsString('evidence_version=n/a mode=legacy_reconstructed_evidence', $result['stdout']);
        self::assertStringContainsString('Replay verification: status=reconstructed_legacy_evidence', $result['stdout']);
        self::assertStringContainsString('Replay attestation: status=not_attested_legacy', $result['stdout']);
        self::assertStringContainsString('Replay trust: level=legacy_reconstructed', $result['stdout']);
        self::assertStringContainsString(
            'Warning: legacy confirmation reconstructed without persisted result_summary; review before enforcing legacy_replay_mode=block.',
            $result['stdout']
        );
        self::assertStringContainsString('Result summary: type=success_no_rows is_select=no affected_rows=2 rows_read=0 column_count=0', $result['stdout']);
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

    /**
     * @param array<string, mixed> $confirmation
     */
    private function computeConfirmationFingerprint(
        DatabaseIdempotencyRecord $record,
        string $sourceNodeId,
        array $confirmation,
    ): string {
        return hash('sha256', json_encode([
            'key_hash' => $record->keyHash,
            'operation_fingerprint' => $record->operationFingerprint,
            'request_id' => $record->requestId,
            'connection_name' => $record->connectionName,
            'logical_target' => $record->logicalTarget,
            'source_node_id' => $sourceNodeId,
            'confirmation' => [
                'kind' => $confirmation['kind'] ?? null,
                'affected_rows' => $confirmation['affected_rows'] ?? null,
                'rows_read' => $confirmation['rows_read'] ?? null,
                'outcome' => $confirmation['outcome'] ?? null,
                'confirmed_at' => $confirmation['confirmed_at'] ?? null,
                'replay_reproducibility' => $confirmation['replay_reproducibility'] ?? null,
                'result_summary' => $confirmation['result_summary'] ?? null,
            ],
        ], JSON_THROW_ON_ERROR));
    }

    private function computeAttestationFingerprint(
        DatabaseIdempotencyRecord $record,
        string $sourceNodeId,
        string $confirmationFingerprint,
        string $attestationMode,
        string $attestedByNodeId,
        string $attestedAt,
    ): string {
        return hash('sha256', json_encode([
            'key_hash' => $record->keyHash,
            'operation_fingerprint' => $record->operationFingerprint,
            'source_node_id' => $sourceNodeId,
            'confirmation_fingerprint' => $confirmationFingerprint,
            'attestation_mode' => $attestationMode,
            'attested_by_node_id' => $attestedByNodeId,
            'attested_at' => $attestedAt,
        ], JSON_THROW_ON_ERROR));
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
