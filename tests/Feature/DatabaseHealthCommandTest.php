<?php

declare(strict_types=1);

namespace VoltStack\Test\Feature;

use PHPUnit\Framework\TestCase;
use Quantum\Console\ConsoleApplication;
use Quantum\Console\Output;
use Quantum\Config\ConfigRepository;
use Quantum\Database\DatabaseContext;
use Quantum\Database\Operation\Contracts\DatabaseIdempotencyStoreInterface;
use Quantum\Database\Operation\DatabaseExecutionPolicy;
use Quantum\Database\Operation\DatabaseIdempotencyRecord;
use Quantum\Database\Operation\DatabaseOperationRuntime;
use Quantum\Database\Operation\Engine\DatabaseRemoteReplayChallengeSigner;
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

    public function test_cli_health_reports_remote_replay_challenge_telemetry(): void
    {
        $app = $this->loadApp();
        $config = $app->make(ConfigRepository::class);
        $config->set('database.idempotency.remote_replay_validation_receipt_max_age_seconds', 600);
        $config->set('database.idempotency.remote_replay_validation_receipt_reuse_scope', 'trusted_nodes');
        $config->set('database.idempotency.remote_replay_validation_receipt_trusted_nodes', ['node-validator-c']);
        $config->set('database.idempotency.remote_replay_challenge.key_id', 'key-2026-09');
        $config->set('database.idempotency.remote_replay_challenge.shared_secret_map', [
            'key-2026-08' => 'cluster-shared-secret-a',
            'key-2026-09' => 'cluster-shared-secret-b',
        ]);
        $router = $app->make(Router::class);
        $router->get('/health-remote-replay', function () use ($app): string {
            /** @var DatabaseContext $context */
            $context = $app->make(DatabaseContext::class);
            /** @var DatabaseOperationRuntime $runtime */
            $runtime = $app->make(DatabaseOperationRuntime::class);
            /** @var DatabaseIdempotencyStoreInterface $store */
            $store = $app->make(DatabaseIdempotencyStoreInterface::class);
            /** @var DatabaseRemoteReplayChallengeSigner $signer */
            $signer = $app->make(DatabaseRemoteReplayChallengeSigner::class);
            $policy = DatabaseExecutionPolicy::fromConfig((array) $app->config('database', []));
            $plan = $runtime->plan(
                new RawOperation(
                    OperationKind::RawExecute,
                    'UPDATE users SET active = 1 WHERE id = 1',
                    [],
                    'primary',
                    'mutation-health-remote-replay',
                ),
                $context,
                $policy,
            );

            $record = new DatabaseIdempotencyRecord(
                keyHash: hash('sha256', 'mutation-health-remote-replay'),
                operationFingerprint: $plan->fingerprint,
                requestId: 'req-health-remote-replay',
                connectionName: 'primary',
                logicalTarget: 'users',
                createdAt: '2026-08-27T10:00:00+00:00',
                nodeId: 'node-remote',
                status: 'pending',
                expiresAt: '2099-08-27T10:05:00+00:00',
            );
            $store->acquire($record);

            $confirmationFingerprint = $this->computeConfirmationFingerprint(
                $record,
                'node-remote',
                [
                    'kind' => 'raw_execute',
                    'affected_rows' => 1,
                    'rows_read' => 0,
                    'outcome' => 'completed',
                    'confirmed_at' => '2026-08-27T10:00:05+00:00',
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
            );
            $validatedAt = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format(DATE_ATOM);
            $receiptAttestation = [
                'version' => 1,
                'mode' => 'validator_node_hmac_sha256',
                'attested_by_node_id' => 'node-validator-c',
                'attested_at' => '2026-08-27T10:00:06+00:00',
                'key_id' => 'key-2026-09',
                'protocol' => $signer->protocol(),
            ];
            $receiptAttestationPayload = [
                'version' => 1,
                'status' => 'verified_remote_validation',
                'validator' => 'remote-validator-original',
                'message' => 'Previously validated by the current node.',
                'validation_mode' => 'require',
                'validated_at' => $validatedAt,
                'validated_by_node_id' => 'node-validator-c',
                'source_node_id' => 'node-remote',
                'confirmation_fingerprint' => $confirmationFingerprint,
                'details' => [
                    'challenge_protocol' => 'remote_replay_node_challenge_v1',
                    'protocol_negotiated' => 'remote_replay_node_challenge_v1',
                    'protocol_compatibility' => 'compatible',
                    'request_key_id' => 'key-2026-08',
                    'response_key_id' => 'key-2026-09',
                    'receipt_reuse' => 'reused_fresh_receipt',
                    'receipt_reuse_scope' => 'trusted_nodes',
                    'receipt_validated_by_node_id' => 'node-validator-c',
                    'receipt_attestation_verification' => 'verified_receipt_attestation',
                    'receipt_attestation_key_id' => 'key-2026-09',
                ],
                'receipt_attestation' => $receiptAttestation,
            ];
            $receiptAttestation['signature'] = $signer->signReceiptAttestation($receiptAttestationPayload, 'key-2026-09');
            $store->complete($record, [
                'kind' => 'raw_execute',
                'affected_rows' => 1,
                'rows_read' => 0,
                'outcome' => 'completed',
                'confirmed_at' => '2026-08-27T10:00:05+00:00',
                'summary_version' => 1,
                'replay_reproducibility' => 'persisted_summary',
                'source_node_id' => 'node-remote',
                'evidence_version' => 1,
                'evidence_mode' => 'persisted_evidence',
                'confirmation_fingerprint' => $confirmationFingerprint,
                'attestation_version' => 1,
                'attestation_mode' => 'source_node_self_attested',
                'attested_by_node_id' => 'node-remote',
                'attested_at' => '2026-08-27T10:00:05+00:00',
                'attestation_fingerprint' => $this->computeAttestationFingerprint(
                    $record,
                    'node-remote',
                    $confirmationFingerprint,
                    'source_node_self_attested',
                    'node-remote',
                    '2026-08-27T10:00:05+00:00',
                ),
                'result_summary' => [
                    'kind' => 'raw_execute',
                    'is_select' => false,
                    'affected_rows' => 1,
                    'rows_read' => 0,
                    'column_count' => 0,
                    'result_type' => 'success_no_rows',
                ],
                'remote_validation_receipt' => [
                    'version' => 1,
                    'status' => 'verified_remote_validation',
                    'validator' => 'remote-validator-original',
                    'message' => 'Previously validated by the current node.',
                    'validation_mode' => 'require',
                    'validated_at' => $validatedAt,
                    'validated_by_node_id' => 'node-validator-c',
                    'source_node_id' => 'node-remote',
                    'confirmation_fingerprint' => $confirmationFingerprint,
                    'details' => [
                        'challenge_protocol' => 'remote_replay_node_challenge_v1',
                        'protocol_negotiated' => 'remote_replay_node_challenge_v1',
                        'protocol_compatibility' => 'compatible',
                        'request_key_id' => 'key-2026-08',
                        'response_key_id' => 'key-2026-09',
                        'receipt_reuse' => 'reused_fresh_receipt',
                        'receipt_reuse_scope' => 'trusted_nodes',
                        'receipt_validated_by_node_id' => 'node-validator-c',
                        'receipt_attestation_verification' => 'verified_receipt_attestation',
                        'receipt_attestation_key_id' => 'key-2026-09',
                    ],
                    'receipt_attestation' => $receiptAttestation,
                ],
            ]);

            $runtime->execute($plan, $context);

            return 'ok';
        });

        $response = $app->make(HttpKernel::class)->handle(Request::create('/health-remote-replay'));
        self::assertSame('ok', $response->content());

        $result = $this->runConsole(['volt', 'db:health']);
        self::assertSame(0, $result['exit']);
        self::assertStringContainsString(
            'Remote replay challenge: observed=1 verified=1 unavailable=0 rejected=0 compatible=1 incompatible=0 reused_receipts=1 cleanup_tombstones=0 protocols=remote_replay_node_challenge_v1:1 request_key_ids=key-2026-08:1 response_key_ids=key-2026-09:1',
            $result['stdout']
        );
        self::assertStringContainsString(
            'rv=verified_remote_validation challenge=remote_replay_node_challenge_v1 compat=compatible key=key-2026-08/key-2026-09 reuse=reused_fresh_receipt reuse_scope=trusted_nodes validated_by=node-validator-c receipt_attestation=verified_receipt_attestation attestation_key=key-2026-09',
            $result['stdout']
        );

        $aggregate = $this->runConsole(['volt', 'db:health', '--aggregate', '--limit=10']);
        self::assertSame(0, $aggregate['exit']);
        self::assertStringContainsString(
            'Remote replay challenge: observed=1 verified=1 unavailable=0 rejected=0 compatible=1 incompatible=0 reused_receipts=1 cleanup_tombstones=0 protocols=remote_replay_node_challenge_v1:1 request_key_ids=key-2026-08:1 response_key_ids=key-2026-09:1',
            $aggregate['stdout']
        );

        $json = $this->runConsole(['volt', 'db:health', '--json']);
        self::assertSame(0, $json['exit']);
        self::assertStringContainsString('"remote_replay_challenge"', $json['stdout']);
        self::assertStringContainsString('"reused_receipts": 1', $json['stdout']);
        self::assertStringContainsString('"challenge_receipt_reuse": "reused_fresh_receipt"', $json['stdout']);
        self::assertStringContainsString('"challenge_receipt_reuse_scope": "trusted_nodes"', $json['stdout']);
        self::assertStringContainsString('"challenge_receipt_validated_by_node_id": "node-validator-c"', $json['stdout']);
        self::assertStringContainsString('"challenge_receipt_attestation_verification": "verified_receipt_attestation"', $json['stdout']);
        self::assertStringContainsString('"challenge_receipt_attestation_key_id": "key-2026-09"', $json['stdout']);
        self::assertStringContainsString('"challenge_receipt_advertisement"', $json['stdout']);
        self::assertStringContainsString('"source_node_id": "node-remote"', $json['stdout']);
        self::assertStringContainsString('"validated_by_node_id": "node-validator-c"', $json['stdout']);
    }

    public function test_cli_health_reports_remote_replay_cleanup_tombstone_telemetry(): void
    {
        $app = $this->loadApp();
        $config = $app->make(ConfigRepository::class);
        $config->set('database.idempotency.remote_replay_validation_mode', 'allow');
        $config->set('database.idempotency.remote_replay_validation_receipt_max_age_seconds', 600);
        $config->set('database.idempotency.remote_replay_validation_receipt_replicated_max_age_seconds', 30);
        $router = $app->make(Router::class);
        $router->get('/health-remote-replay-cleanup', function () use ($app): string {
            /** @var DatabaseContext $context */
            $context = $app->make(DatabaseContext::class);
            /** @var DatabaseOperationRuntime $runtime */
            $runtime = $app->make(DatabaseOperationRuntime::class);
            /** @var DatabaseIdempotencyStoreInterface $store */
            $store = $app->make(DatabaseIdempotencyStoreInterface::class);
            $policy = DatabaseExecutionPolicy::fromConfig((array) $app->config('database', []));
            $plan = $runtime->plan(
                new RawOperation(
                    OperationKind::RawExecute,
                    'UPDATE users SET active = 1 WHERE id = 1',
                    [],
                    'primary',
                    'mutation-health-remote-replay-cleanup',
                ),
                $context,
                $policy,
            );

            $record = new DatabaseIdempotencyRecord(
                keyHash: hash('sha256', 'mutation-health-remote-replay-cleanup'),
                operationFingerprint: $plan->fingerprint,
                requestId: 'req-health-remote-replay-cleanup',
                connectionName: 'primary',
                logicalTarget: 'users',
                createdAt: '2026-08-27T11:00:00+00:00',
                nodeId: 'node-remote',
                status: 'pending',
                expiresAt: '2099-08-27T11:05:00+00:00',
            );
            $store->acquire($record);

            $confirmationFingerprint = $this->computeConfirmationFingerprint(
                $record,
                'node-remote',
                [
                    'kind' => 'raw_execute',
                    'affected_rows' => 1,
                    'rows_read' => 0,
                    'outcome' => 'completed',
                    'confirmed_at' => '2026-08-27T11:00:05+00:00',
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
            );
            $store->complete($record, [
                'kind' => 'raw_execute',
                'affected_rows' => 1,
                'rows_read' => 0,
                'outcome' => 'completed',
                'confirmed_at' => '2026-08-27T11:00:05+00:00',
                'summary_version' => 1,
                'replay_reproducibility' => 'persisted_summary',
                'source_node_id' => 'node-remote',
                'evidence_version' => 1,
                'evidence_mode' => 'persisted_evidence',
                'confirmation_fingerprint' => $confirmationFingerprint,
                'attestation_version' => 1,
                'attestation_mode' => 'source_node_self_attested',
                'attested_by_node_id' => 'node-remote',
                'attested_at' => '2026-08-27T11:00:05+00:00',
                'attestation_fingerprint' => $this->computeAttestationFingerprint(
                    $record,
                    'node-remote',
                    $confirmationFingerprint,
                    'source_node_self_attested',
                    'node-remote',
                    '2026-08-27T11:00:05+00:00',
                ),
                'result_summary' => [
                    'kind' => 'raw_execute',
                    'is_select' => false,
                    'affected_rows' => 1,
                    'rows_read' => 0,
                    'column_count' => 0,
                    'result_type' => 'success_no_rows',
                ],
                'remote_validation_receipt' => [
                    'version' => 1,
                    'status' => 'verified_remote_validation',
                    'validator' => 'remote-validator-original',
                    'message' => 'Old replicated copy that should be pruned.',
                    'validation_mode' => 'allow',
                    'validated_at' => '2026-08-27T11:00:06+00:00',
                    'validated_by_node_id' => 'node-validator-c',
                    'source_node_id' => 'node-remote',
                    'confirmation_fingerprint' => $confirmationFingerprint,
                    'details' => [
                        'challenge_protocol' => 'remote_replay_node_challenge_v1',
                        'confirmation_fingerprint' => $confirmationFingerprint,
                        'receipt_reuse_source' => 'health_snapshot',
                        'receipt_propagation_source' => 'health_snapshot',
                        'receipt_propagation_report_node_id' => 'node-validator-c',
                        'receipt_propagation_generated_at' => '2026-08-27T11:00:07+00:00',
                        'receipt_replicated_at' => '2026-08-27T11:00:08+00:00',
                        'receipt_replicated_by_node_id' => 'VoltStack Health Command Feature Test',
                    ],
                ],
            ]);

            $runtime->execute($plan, $context);

            return 'ok';
        });

        $response = $app->make(HttpKernel::class)->handle(Request::create('/health-remote-replay-cleanup'));
        self::assertSame('ok', $response->content());

        $result = $this->runConsole(['volt', 'db:health']);
        self::assertSame(0, $result['exit']);
        self::assertStringContainsString('cleanup_tombstones=1', $result['stdout']);
        self::assertStringContainsString('receipt_tombstone=expired_local_replica tombstone_source=node-remote', $result['stdout']);

        $json = $this->runConsole(['volt', 'db:health', '--json']);
        self::assertSame(0, $json['exit']);
        self::assertStringContainsString('"cleanup_tombstones": 1', $json['stdout']);
        self::assertStringContainsString('"challenge_receipt_tombstone_advertisement"', $json['stdout']);
        self::assertStringContainsString('"reason": "expired_local_replica"', $json['stdout']);
        self::assertStringContainsString('"source_node_id": "node-remote"', $json['stdout']);
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
                'idempotency' => [
                    'store' => 'directory',
                    'directory_path' => $basePath . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'framework' . DIRECTORY_SEPARATOR . 'database' . DIRECTORY_SEPARATOR . 'idempotency',
                    'node_id' => 'node-local',
                    'pending_ttl_seconds' => 300,
                    'remote_replay_attestation_mode' => 'require',
                    'remote_replay_validation_mode' => 'require',
                    'remote_replay_validation_receipt_max_age_seconds' => 600,
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
