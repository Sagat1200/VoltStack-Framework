<?php

declare(strict_types=1);

namespace VoltStack\Test\Unit;

use PHPUnit\Framework\TestCase;
use Quantum\Database\Capability\DatabaseCapabilitySet;
use Quantum\Database\DatabaseContext;
use Quantum\Database\Dbal\Contract\ConnectionInterface;
use Quantum\Database\Dbal\Contract\StatementInterface;
use Quantum\Database\Dbal\Enum\DatabaseFailureKind;
use Quantum\Database\Dbal\Enum\TransactionIsolation;
use Quantum\Database\Dbal\Exception\DbalException;
use Quantum\Database\Dbal\Value\DriverInfo;
use Quantum\Database\Dbal\Value\QueryResult;
use Quantum\Database\Operation\Contracts\DatabaseRemoteReplayValidatorInterface;
use Quantum\Database\Operation\DatabaseCircuitBreaker;
use Quantum\Database\Operation\DatabaseExecutionPolicy;
use Quantum\Database\Operation\DatabaseIdempotencyRecord;
use Quantum\Database\Operation\DatabaseOperationException;
use Quantum\Database\Operation\DatabaseOperationRuntime;
use Quantum\Database\Operation\DatabaseRemoteReplayValidationResult;
use Quantum\Database\Operation\DatabaseTelemetryReport;
use Quantum\Database\Operation\DatabaseTelemetryStore;
use Quantum\Database\Operation\Engine\DirectoryDatabaseIdempotencyStore;
use Quantum\Database\Operation\Engine\InMemoryDatabaseHealthStore;
use Quantum\Database\Operation\OperationKind;
use Quantum\Database\Operation\RawOperation;

final class DatabaseOperationRuntimeTest extends TestCase
{
    private ?string $idempotencyBasePath = null;

    protected function tearDown(): void
    {
        if (is_string($this->idempotencyBasePath) && is_dir($this->idempotencyBasePath)) {
            $this->deleteDirectory($this->idempotencyBasePath);
        }

        $this->idempotencyBasePath = null;

        parent::tearDown();
    }

    public function test_runtime_retries_transient_raw_query_until_success(): void
    {
        $connection = new RuntimeTestConnection([
            DbalException::wrap(new \RuntimeException('temporary disconnect'), DatabaseFailureKind::Connectivity, 'stmt.execute', 'SELECT 1', true),
            RuntimeTestConnection::queryResult([
                ['value' => 1],
            ]),
        ]);
        $runtime = new DatabaseOperationRuntime(new DatabaseCircuitBreaker());
        $context = DatabaseContext::empty()->withConnection($connection);
        $policy = new DatabaseExecutionPolicy(retryLimit: 1, retryBackoffMs: 0, circuitFailureThreshold: 3);
        $plan = $runtime->plan(new RawOperation(OperationKind::RawQuery, 'SELECT 1', [], 'primary'), $context, $policy);

        $result = $runtime->execute($plan, $context);
        /** @var \Quantum\Database\Operation\DatabaseDiagnosticSnapshot $diagnostic */
        $diagnostic = $result->debug['diagnostic'];

        self::assertTrue($result->isSuccess);
        self::assertSame(2, $diagnostic->attempts);
        self::assertSame('completed', $diagnostic->outcome);
        self::assertSame(2, $connection->queryCalls);
    }

    public function test_runtime_retries_idempotent_mutating_operation_until_success(): void
    {
        $connection = new RuntimeTestConnection(
            statementQueue: [
                DbalException::wrap(new \RuntimeException('temporary statement disconnect'), DatabaseFailureKind::Connectivity, 'stmt.execute', 'UPDATE users SET active = 1 WHERE id = 1', true),
                RuntimeTestConnection::statementResult(1),
            ],
        );
        $runtime = new DatabaseOperationRuntime(new DatabaseCircuitBreaker());
        $context = DatabaseContext::empty()->withConnection($connection);
        $policy = new DatabaseExecutionPolicy(
            retryLimit: 1,
            retryBackoffMs: 0,
            retryMutationsWhenIdempotent: true,
            circuitFailureThreshold: 3,
        );
        $plan = $runtime->plan(
            new RawOperation(
                OperationKind::RawExecute,
                'UPDATE users SET active = 1 WHERE id = 1',
                [],
                'primary',
                'mutation-users-1',
            ),
            $context,
            $policy,
        );

        $result = $runtime->execute($plan, $context);
        /** @var \Quantum\Database\Operation\DatabaseDiagnosticSnapshot $diagnostic */
        $diagnostic = $result->debug['diagnostic'];

        self::assertTrue($result->isSuccess);
        self::assertTrue($plan->retryable);
        self::assertSame(2, $diagnostic->attempts);
        self::assertSame('completed', $diagnostic->outcome);
        self::assertSame(2, $connection->statementCalls);
    }

    public function test_runtime_does_not_retry_mutating_operation_without_idempotency_key(): void
    {
        $connection = new RuntimeTestConnection(
            statementQueue: [
                DbalException::wrap(new \RuntimeException('temporary statement disconnect'), DatabaseFailureKind::Connectivity, 'stmt.execute', 'UPDATE users SET active = 1 WHERE id = 1', true),
            ],
        );
        $runtime = new DatabaseOperationRuntime(new DatabaseCircuitBreaker());
        $context = DatabaseContext::empty()->withConnection($connection);
        $policy = new DatabaseExecutionPolicy(
            retryLimit: 1,
            retryBackoffMs: 0,
            retryMutationsWhenIdempotent: true,
            circuitFailureThreshold: 3,
        );
        $plan = $runtime->plan(
            new RawOperation(OperationKind::RawExecute, 'UPDATE users SET active = 1 WHERE id = 1', [], 'primary'),
            $context,
            $policy,
        );

        self::assertFalse($plan->retryable);
        self::assertSame(0, $plan->retryLimit);

        try {
            $runtime->execute($plan, $context);
            self::fail('Mutation without idempotency key should not be retried.');
        } catch (DatabaseOperationException $e) {
            self::assertSame('transient', $e->failure->value);
            self::assertFalse($e->snapshot->retryable);
            self::assertSame(1, $e->snapshot->attempts);
        }

        self::assertSame(1, $connection->statementCalls);
    }

    public function test_runtime_blocks_duplicate_idempotent_mutation_when_key_is_already_reserved(): void
    {
        $this->idempotencyBasePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'voltstack-db-idempotency-runtime-' . uniqid('', true);
        mkdir($this->idempotencyBasePath, 0777, true);

        $store = new DirectoryDatabaseIdempotencyStore($this->idempotencyBasePath . DIRECTORY_SEPARATOR . 'idempotency');
        $keyHash = hash('sha256', 'mutation-users-1');
        $store->acquire(new DatabaseIdempotencyRecord(
            keyHash: $keyHash,
            operationFingerprint: 'existing-plan',
            requestId: 'req-existing',
            connectionName: 'primary',
            logicalTarget: 'users',
            createdAt: '2026-08-25T00:00:00+00:00',
            nodeId: 'node-a',
            status: 'pending',
        ));

        $connection = new RuntimeTestConnection(
            statementQueue: [
                RuntimeTestConnection::statementResult(1),
            ],
        );
        $runtime = new DatabaseOperationRuntime(
            new DatabaseCircuitBreaker(),
            idempotencyStore: $store,
            idempotencyNodeId: 'node-b',
        );
        $context = DatabaseContext::empty()->withConnection($connection);
        $policy = new DatabaseExecutionPolicy(
            retryLimit: 1,
            retryBackoffMs: 0,
            retryMutationsWhenIdempotent: true,
        );
        $plan = $runtime->plan(
            new RawOperation(
                OperationKind::RawExecute,
                'UPDATE users SET active = 1 WHERE id = 1',
                [],
                'primary',
                'mutation-users-1',
            ),
            $context,
            $policy,
        );

        try {
            $runtime->execute($plan, $context);
            self::fail('Reserved idempotency key should block duplicate execution.');
        } catch (DatabaseOperationException $e) {
            self::assertSame('duplicate', $e->failure->value);
            self::assertSame('cancelled', $e->snapshot->outcome);
            self::assertSame('idempotency_guard_conflict', $e->snapshot->events[1]->details['reason'] ?? null);
        }

        self::assertSame(0, $connection->statementCalls);
    }

    public function test_runtime_reclaims_expired_pending_idempotency_record_and_executes_mutation(): void
    {
        $this->idempotencyBasePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'voltstack-db-idempotency-runtime-' . uniqid('', true);
        mkdir($this->idempotencyBasePath, 0777, true);

        $store = new DirectoryDatabaseIdempotencyStore($this->idempotencyBasePath . DIRECTORY_SEPARATOR . 'idempotency');
        $store->acquire(new DatabaseIdempotencyRecord(
            keyHash: hash('sha256', 'mutation-users-expired'),
            operationFingerprint: 'expired-plan',
            requestId: 'req-expired',
            connectionName: 'primary',
            logicalTarget: 'users',
            createdAt: '2026-08-24T00:00:00+00:00',
            nodeId: 'node-a',
            status: 'pending',
            expiresAt: '2026-08-24T00:05:00+00:00',
        ));

        $connection = new RuntimeTestConnection(
            statementQueue: [
                RuntimeTestConnection::statementResult(1),
            ],
        );
        $runtime = new DatabaseOperationRuntime(
            new DatabaseCircuitBreaker(),
            idempotencyStore: $store,
            idempotencyNodeId: 'node-b',
        );
        $context = DatabaseContext::empty()->withConnection($connection);
        $policy = new DatabaseExecutionPolicy(
            retryLimit: 1,
            retryBackoffMs: 0,
            retryMutationsWhenIdempotent: true,
            idempotencyPendingTtlSeconds: 300,
            remoteReplayAttestationMode: 'require',
        );
        $plan = $runtime->plan(
            new RawOperation(
                OperationKind::RawExecute,
                'UPDATE users SET active = 1 WHERE id = 1',
                [],
                'primary',
                'mutation-users-expired',
            ),
            $context,
            $policy,
        );

        $result = $runtime->execute($plan, $context);

        self::assertTrue($result->isSuccess);
        self::assertSame(1, $connection->statementCalls);
        self::assertSame('completed', $store->find(hash('sha256', 'mutation-users-expired'))?->status);
    }

    public function test_runtime_persists_configured_idempotency_node_id(): void
    {
        $this->idempotencyBasePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'voltstack-db-idempotency-runtime-' . uniqid('', true);
        mkdir($this->idempotencyBasePath, 0777, true);

        $store = new DirectoryDatabaseIdempotencyStore($this->idempotencyBasePath . DIRECTORY_SEPARATOR . 'idempotency');
        $connection = new RuntimeTestConnection(
            statementQueue: [
                RuntimeTestConnection::statementResult(1),
            ],
        );
        $runtime = new DatabaseOperationRuntime(
            new DatabaseCircuitBreaker(),
            idempotencyStore: $store,
            idempotencyNodeId: 'node-runtime-a',
        );
        $context = DatabaseContext::empty()->withConnection($connection);
        $policy = new DatabaseExecutionPolicy(
            retryLimit: 1,
            retryBackoffMs: 0,
            retryMutationsWhenIdempotent: true,
            idempotencyPendingTtlSeconds: 300,
        );
        $plan = $runtime->plan(
            new RawOperation(
                OperationKind::RawExecute,
                'UPDATE users SET active = 1 WHERE id = 1',
                [],
                'primary',
                'mutation-users-node',
            ),
            $context,
            $policy,
        );

        $result = $runtime->execute($plan, $context);

        self::assertTrue($result->isSuccess);
        self::assertSame('node-runtime-a', $store->find(hash('sha256', 'mutation-users-node'))?->nodeId);
    }

    public function test_runtime_persists_confirmation_metadata_on_completed_idempotent_mutation(): void
    {
        $this->idempotencyBasePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'voltstack-db-idempotency-runtime-' . uniqid('', true);
        mkdir($this->idempotencyBasePath, 0777, true);

        $store = new DirectoryDatabaseIdempotencyStore($this->idempotencyBasePath . DIRECTORY_SEPARATOR . 'idempotency');
        $connection = new RuntimeTestConnection(
            statementQueue: [
                RuntimeTestConnection::statementResult(3),
            ],
        );
        $runtime = new DatabaseOperationRuntime(
            new DatabaseCircuitBreaker(),
            idempotencyStore: $store,
            idempotencyNodeId: 'node-b',
        );
        $context = DatabaseContext::empty()->withConnection($connection);
        $policy = new DatabaseExecutionPolicy(
            retryLimit: 1,
            retryBackoffMs: 0,
            retryMutationsWhenIdempotent: true,
            idempotencyPendingTtlSeconds: 300,
        );
        $plan = $runtime->plan(
            new RawOperation(
                OperationKind::RawExecute,
                'UPDATE users SET active = 1 WHERE active = 0',
                [],
                'primary',
                'mutation-users-confirmation',
            ),
            $context,
            $policy,
        );

        $result = $runtime->execute($plan, $context);
        $stored = $store->find(hash('sha256', 'mutation-users-confirmation'));

        self::assertTrue($result->isSuccess);
        self::assertSame('completed', $stored?->status);
        self::assertSame('raw_execute', $stored?->confirmation['kind'] ?? null);
        self::assertSame(3, $stored?->confirmation['affected_rows'] ?? null);
        self::assertSame(0, $stored?->confirmation['rows_read'] ?? null);
        self::assertSame(1, $stored?->confirmation['summary_version'] ?? null);
        self::assertSame('persisted_summary', $stored?->confirmation['replay_reproducibility'] ?? null);
        self::assertSame('success_no_rows', $stored?->confirmation['result_summary']['result_type'] ?? null);
        self::assertSame(3, $stored?->confirmation['result_summary']['affected_rows'] ?? null);
        self::assertSame(0, $stored?->confirmation['result_summary']['rows_read'] ?? null);
        self::assertSame('node-b', $stored?->confirmation['source_node_id'] ?? null);
        self::assertSame(1, $stored?->confirmation['evidence_version'] ?? null);
        self::assertSame('persisted_evidence', $stored?->confirmation['evidence_mode'] ?? null);
        self::assertIsString($stored?->confirmation['confirmation_fingerprint'] ?? null);
        self::assertSame(1, $stored?->confirmation['attestation_version'] ?? null);
        self::assertSame('source_node_self_attested', $stored?->confirmation['attestation_mode'] ?? null);
        self::assertSame('node-b', $stored?->confirmation['attested_by_node_id'] ?? null);
        self::assertSame($stored?->confirmation['confirmed_at'] ?? null, $stored?->confirmation['attested_at'] ?? null);
        self::assertIsString($stored?->confirmation['attestation_fingerprint'] ?? null);
        self::assertSame('completed', $result->debug['idempotency']['status'] ?? null);
        self::assertSame('persisted_summary', $result->debug['idempotency']['confirmation']['replay_reproducibility'] ?? null);
        self::assertSame('persisted_evidence', $result->debug['idempotency']['confirmation']['evidence_mode'] ?? null);
        self::assertSame('success_no_rows', $result->debug['idempotency']['result_summary']['result_type'] ?? null);
    }

    public function test_runtime_classifies_remote_replay_as_attested_when_source_node_attestation_verifies(): void
    {
        $this->idempotencyBasePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'voltstack-db-idempotency-runtime-' . uniqid('', true);
        mkdir($this->idempotencyBasePath, 0777, true);

        $store = new DirectoryDatabaseIdempotencyStore($this->idempotencyBasePath . DIRECTORY_SEPARATOR . 'idempotency');
        $connection = new RuntimeTestConnection(
            statementQueue: [
                RuntimeTestConnection::statementResult(1),
            ],
        );
        $runtime = new DatabaseOperationRuntime(
            new DatabaseCircuitBreaker(),
            idempotencyStore: $store,
            idempotencyNodeId: 'node-b',
        );
        $context = DatabaseContext::empty()->withConnection($connection);
        $policy = new DatabaseExecutionPolicy(
            retryLimit: 1,
            retryBackoffMs: 0,
            retryMutationsWhenIdempotent: true,
            idempotencyPendingTtlSeconds: 300,
        );
        $plan = $runtime->plan(
            new RawOperation(
                OperationKind::RawExecute,
                'UPDATE users SET active = 1 WHERE id = 1',
                [],
                'primary',
                'mutation-users-remote-attested',
            ),
            $context,
            $policy,
        );

        $record = new DatabaseIdempotencyRecord(
            keyHash: hash('sha256', 'mutation-users-remote-attested'),
            operationFingerprint: $plan->fingerprint,
            requestId: 'req-remote-attested',
            connectionName: 'primary',
            logicalTarget: 'users',
            createdAt: '2026-08-26T01:00:00+00:00',
            nodeId: 'node-a',
            status: 'pending',
            expiresAt: '2099-08-26T01:05:00+00:00',
        );
        $store->acquire($record);
        $confirmationFingerprint = $this->computeConfirmationFingerprintForTest(
            $record,
            'node-a',
            [
                'kind' => 'raw_execute',
                'affected_rows' => 1,
                'rows_read' => 0,
                'outcome' => 'completed',
                'confirmed_at' => '2026-08-26T01:00:05+00:00',
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
            'confirmed_at' => '2026-08-26T01:00:05+00:00',
            'summary_version' => 1,
            'replay_reproducibility' => 'persisted_summary',
            'source_node_id' => 'node-a',
            'evidence_version' => 1,
            'evidence_mode' => 'persisted_evidence',
            'confirmation_fingerprint' => $confirmationFingerprint,
            'attestation_version' => 1,
            'attestation_mode' => 'source_node_self_attested',
            'attested_by_node_id' => 'node-a',
            'attested_at' => '2026-08-26T01:00:05+00:00',
            'attestation_fingerprint' => $this->computeAttestationFingerprintForTest(
                $record,
                'node-a',
                $confirmationFingerprint,
                'source_node_self_attested',
                'node-a',
                '2026-08-26T01:00:05+00:00',
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

        $result = $runtime->execute($plan, $context);

        self::assertTrue($result->isSuccess);
        self::assertSame(0, $connection->statementCalls);
        self::assertSame('remote_attested_persisted', $result->debug['idempotency']['evidence_trust_level'] ?? null);
        self::assertSame('verified_persisted_evidence', $result->debug['idempotency']['confirmation_evidence']['verification_status'] ?? null);
        self::assertSame('verified_source_node_attestation', $result->debug['idempotency']['confirmation_evidence']['attestation_verification_status'] ?? null);
    }

    public function test_runtime_emits_warning_for_remote_replay_without_verified_attestation_when_policy_is_warn(): void
    {
        $this->idempotencyBasePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'voltstack-db-idempotency-runtime-' . uniqid('', true);
        mkdir($this->idempotencyBasePath, 0777, true);

        $store = new DirectoryDatabaseIdempotencyStore($this->idempotencyBasePath . DIRECTORY_SEPARATOR . 'idempotency');
        $connection = new RuntimeTestConnection(
            statementQueue: [
                RuntimeTestConnection::statementResult(1),
            ],
        );
        $runtime = new DatabaseOperationRuntime(
            new DatabaseCircuitBreaker(),
            idempotencyStore: $store,
            idempotencyNodeId: 'node-b',
        );
        $context = DatabaseContext::empty()->withConnection($connection);
        $policy = new DatabaseExecutionPolicy(
            retryLimit: 1,
            retryBackoffMs: 0,
            retryMutationsWhenIdempotent: true,
            idempotencyPendingTtlSeconds: 300,
            remoteReplayAttestationMode: 'warn',
        );
        $plan = $runtime->plan(
            new RawOperation(
                OperationKind::RawExecute,
                'UPDATE users SET active = 1 WHERE id = 1',
                [],
                'primary',
                'mutation-users-remote-warn',
            ),
            $context,
            $policy,
        );

        $record = new DatabaseIdempotencyRecord(
            keyHash: hash('sha256', 'mutation-users-remote-warn'),
            operationFingerprint: $plan->fingerprint,
            requestId: 'req-remote-warn',
            connectionName: 'primary',
            logicalTarget: 'users',
            createdAt: '2026-08-26T03:00:00+00:00',
            nodeId: 'node-a',
            status: 'pending',
            expiresAt: '2099-08-26T03:05:00+00:00',
        );
        $store->acquire($record);
        $confirmationFingerprint = $this->computeConfirmationFingerprintForTest(
            $record,
            'node-a',
            [
                'kind' => 'raw_execute',
                'affected_rows' => 1,
                'rows_read' => 0,
                'outcome' => 'completed',
                'confirmed_at' => '2026-08-26T03:00:05+00:00',
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
            'confirmed_at' => '2026-08-26T03:00:05+00:00',
            'summary_version' => 1,
            'replay_reproducibility' => 'persisted_summary',
            'source_node_id' => 'node-a',
            'evidence_version' => 1,
            'evidence_mode' => 'persisted_evidence',
            'confirmation_fingerprint' => $confirmationFingerprint,
            'result_summary' => [
                'kind' => 'raw_execute',
                'is_select' => false,
                'affected_rows' => 1,
                'rows_read' => 0,
                'column_count' => 0,
                'result_type' => 'success_no_rows',
            ],
        ]);

        $result = $runtime->execute($plan, $context);
        $warning = $result->debug['idempotency']['attestation_warning'] ?? null;
        $reasons = array_map(
            static fn ($event): ?string => is_object($event) && isset($event->details) && is_array($event->details)
                ? ($event->details['reason'] ?? null)
                : null,
            $result->debug['diagnostic']->events ?? [],
        );

        self::assertTrue($result->isSuccess);
        self::assertSame(0, $connection->statementCalls);
        self::assertSame('remote_verified_persisted', $result->debug['idempotency']['evidence_trust_level'] ?? null);
        self::assertIsArray($warning);
        self::assertSame('idempotency_guard_remote_replay_attestation_warning', $warning['reason'] ?? null);
        self::assertSame('warn', $warning['remote_replay_attestation_mode'] ?? null);
        self::assertSame('no_attestation', $warning['attestation_verification_status'] ?? null);
        self::assertContains('idempotency_guard_remote_replay_attestation_warning', $reasons);
        self::assertContains('idempotency_guard_replayed_confirmed', $reasons);
    }

    public function test_runtime_blocks_remote_replay_without_verified_attestation_when_policy_requires_it(): void
    {
        $this->idempotencyBasePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'voltstack-db-idempotency-runtime-' . uniqid('', true);
        mkdir($this->idempotencyBasePath, 0777, true);

        $store = new DirectoryDatabaseIdempotencyStore($this->idempotencyBasePath . DIRECTORY_SEPARATOR . 'idempotency');
        $connection = new RuntimeTestConnection(
            statementQueue: [
                RuntimeTestConnection::statementResult(1),
            ],
        );
        $runtime = new DatabaseOperationRuntime(
            new DatabaseCircuitBreaker(),
            idempotencyStore: $store,
            idempotencyNodeId: 'node-b',
        );
        $context = DatabaseContext::empty()->withConnection($connection);
        $policy = new DatabaseExecutionPolicy(
            retryLimit: 1,
            retryBackoffMs: 0,
            retryMutationsWhenIdempotent: true,
            idempotencyPendingTtlSeconds: 300,
            remoteReplayAttestationMode: 'require',
        );
        $plan = $runtime->plan(
            new RawOperation(
                OperationKind::RawExecute,
                'UPDATE users SET active = 1 WHERE id = 1',
                [],
                'primary',
                'mutation-users-remote-require',
            ),
            $context,
            $policy,
        );

        $record = new DatabaseIdempotencyRecord(
            keyHash: hash('sha256', 'mutation-users-remote-require'),
            operationFingerprint: $plan->fingerprint,
            requestId: 'req-remote-require',
            connectionName: 'primary',
            logicalTarget: 'users',
            createdAt: '2026-08-26T04:00:00+00:00',
            nodeId: 'node-a',
            status: 'pending',
            expiresAt: '2099-08-26T04:05:00+00:00',
        );
        $store->acquire($record);
        $confirmationFingerprint = $this->computeConfirmationFingerprintForTest(
            $record,
            'node-a',
            [
                'kind' => 'raw_execute',
                'affected_rows' => 1,
                'rows_read' => 0,
                'outcome' => 'completed',
                'confirmed_at' => '2026-08-26T04:00:05+00:00',
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
            'confirmed_at' => '2026-08-26T04:00:05+00:00',
            'summary_version' => 1,
            'replay_reproducibility' => 'persisted_summary',
            'source_node_id' => 'node-a',
            'evidence_version' => 1,
            'evidence_mode' => 'persisted_evidence',
            'confirmation_fingerprint' => $confirmationFingerprint,
            'result_summary' => [
                'kind' => 'raw_execute',
                'is_select' => false,
                'affected_rows' => 1,
                'rows_read' => 0,
                'column_count' => 0,
                'result_type' => 'success_no_rows',
            ],
        ]);

        try {
            $runtime->execute($plan, $context);
            self::fail('Remote replay should be blocked when verified attestation is required.');
        } catch (DatabaseOperationException $e) {
            self::assertSame('verification_failed', $e->failure->value);
            self::assertSame('cancelled', $e->snapshot->outcome);
            self::assertSame(0, $connection->statementCalls);
            $reasons = array_map(
                static fn ($event): ?string => is_object($event) && isset($event->details) && is_array($event->details)
                    ? ($event->details['reason'] ?? null)
                    : null,
                $e->snapshot->events,
            );
            self::assertContains('idempotency_guard_remote_replay_attestation_required', $reasons);
        }
    }

    public function test_runtime_emits_warning_for_stale_remote_attestation_when_policy_is_warn(): void
    {
        $this->idempotencyBasePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'voltstack-db-idempotency-runtime-' . uniqid('', true);
        mkdir($this->idempotencyBasePath, 0777, true);

        $store = new DirectoryDatabaseIdempotencyStore($this->idempotencyBasePath . DIRECTORY_SEPARATOR . 'idempotency');
        $connection = new RuntimeTestConnection(
            statementQueue: [
                RuntimeTestConnection::statementResult(1),
            ],
        );
        $runtime = new DatabaseOperationRuntime(
            new DatabaseCircuitBreaker(),
            idempotencyStore: $store,
            idempotencyNodeId: 'node-b',
        );
        $context = DatabaseContext::empty()->withConnection($connection);
        $policy = new DatabaseExecutionPolicy(
            retryLimit: 1,
            retryBackoffMs: 0,
            retryMutationsWhenIdempotent: true,
            idempotencyPendingTtlSeconds: 300,
            remoteReplayAttestationMode: 'warn',
            remoteReplayAttestationMaxAgeSeconds: 60,
        );
        $plan = $runtime->plan(
            new RawOperation(
                OperationKind::RawExecute,
                'UPDATE users SET active = 1 WHERE id = 1',
                [],
                'primary',
                'mutation-users-remote-stale-warn',
            ),
            $context,
            $policy,
        );

        $record = new DatabaseIdempotencyRecord(
            keyHash: hash('sha256', 'mutation-users-remote-stale-warn'),
            operationFingerprint: $plan->fingerprint,
            requestId: 'req-remote-stale-warn',
            connectionName: 'primary',
            logicalTarget: 'users',
            createdAt: '2026-08-26T05:00:00+00:00',
            nodeId: 'node-a',
            status: 'pending',
            expiresAt: '2099-08-26T05:05:00+00:00',
        );
        $store->acquire($record);
        $confirmationFingerprint = $this->computeConfirmationFingerprintForTest(
            $record,
            'node-a',
            [
                'kind' => 'raw_execute',
                'affected_rows' => 1,
                'rows_read' => 0,
                'outcome' => 'completed',
                'confirmed_at' => '2026-08-20T05:00:05+00:00',
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
            'confirmed_at' => '2026-08-20T05:00:05+00:00',
            'summary_version' => 1,
            'replay_reproducibility' => 'persisted_summary',
            'source_node_id' => 'node-a',
            'evidence_version' => 1,
            'evidence_mode' => 'persisted_evidence',
            'confirmation_fingerprint' => $confirmationFingerprint,
            'attestation_version' => 1,
            'attestation_mode' => 'source_node_self_attested',
            'attested_by_node_id' => 'node-a',
            'attested_at' => '2026-08-20T05:00:05+00:00',
            'attestation_fingerprint' => $this->computeAttestationFingerprintForTest(
                $record,
                'node-a',
                $confirmationFingerprint,
                'source_node_self_attested',
                'node-a',
                '2026-08-20T05:00:05+00:00',
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

        $result = $runtime->execute($plan, $context);
        $warning = $result->debug['idempotency']['attestation_warning'] ?? null;
        $reasons = array_map(
            static fn ($event): ?string => is_object($event) && isset($event->details) && is_array($event->details)
                ? ($event->details['reason'] ?? null)
                : null,
            $result->debug['diagnostic']->events ?? [],
        );

        self::assertTrue($result->isSuccess);
        self::assertSame(0, $connection->statementCalls);
        self::assertSame('stale_verified_attestation', $result->debug['idempotency']['confirmation_evidence']['attestation_freshness_status'] ?? null);
        self::assertIsInt($result->debug['idempotency']['confirmation_evidence']['attestation_age_seconds'] ?? null);
        self::assertIsArray($warning);
        self::assertSame('idempotency_guard_remote_replay_attestation_stale_warning', $warning['reason'] ?? null);
        self::assertContains('idempotency_guard_remote_replay_attestation_stale_warning', $reasons);
    }

    public function test_runtime_blocks_remote_replay_when_verified_attestation_is_stale_and_policy_requires_it(): void
    {
        $this->idempotencyBasePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'voltstack-db-idempotency-runtime-' . uniqid('', true);
        mkdir($this->idempotencyBasePath, 0777, true);

        $store = new DirectoryDatabaseIdempotencyStore($this->idempotencyBasePath . DIRECTORY_SEPARATOR . 'idempotency');
        $connection = new RuntimeTestConnection(
            statementQueue: [
                RuntimeTestConnection::statementResult(1),
            ],
        );
        $runtime = new DatabaseOperationRuntime(
            new DatabaseCircuitBreaker(),
            idempotencyStore: $store,
            idempotencyNodeId: 'node-b',
        );
        $context = DatabaseContext::empty()->withConnection($connection);
        $policy = new DatabaseExecutionPolicy(
            retryLimit: 1,
            retryBackoffMs: 0,
            retryMutationsWhenIdempotent: true,
            idempotencyPendingTtlSeconds: 300,
            remoteReplayAttestationMode: 'require',
            remoteReplayAttestationMaxAgeSeconds: 60,
        );
        $plan = $runtime->plan(
            new RawOperation(
                OperationKind::RawExecute,
                'UPDATE users SET active = 1 WHERE id = 1',
                [],
                'primary',
                'mutation-users-remote-stale-require',
            ),
            $context,
            $policy,
        );

        $record = new DatabaseIdempotencyRecord(
            keyHash: hash('sha256', 'mutation-users-remote-stale-require'),
            operationFingerprint: $plan->fingerprint,
            requestId: 'req-remote-stale-require',
            connectionName: 'primary',
            logicalTarget: 'users',
            createdAt: '2026-08-26T06:00:00+00:00',
            nodeId: 'node-a',
            status: 'pending',
            expiresAt: '2099-08-26T06:05:00+00:00',
        );
        $store->acquire($record);
        $confirmationFingerprint = $this->computeConfirmationFingerprintForTest(
            $record,
            'node-a',
            [
                'kind' => 'raw_execute',
                'affected_rows' => 1,
                'rows_read' => 0,
                'outcome' => 'completed',
                'confirmed_at' => '2026-08-20T06:00:05+00:00',
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
            'confirmed_at' => '2026-08-20T06:00:05+00:00',
            'summary_version' => 1,
            'replay_reproducibility' => 'persisted_summary',
            'source_node_id' => 'node-a',
            'evidence_version' => 1,
            'evidence_mode' => 'persisted_evidence',
            'confirmation_fingerprint' => $confirmationFingerprint,
            'attestation_version' => 1,
            'attestation_mode' => 'source_node_self_attested',
            'attested_by_node_id' => 'node-a',
            'attested_at' => '2026-08-20T06:00:05+00:00',
            'attestation_fingerprint' => $this->computeAttestationFingerprintForTest(
                $record,
                'node-a',
                $confirmationFingerprint,
                'source_node_self_attested',
                'node-a',
                '2026-08-20T06:00:05+00:00',
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

        try {
            $runtime->execute($plan, $context);
            self::fail('Remote replay should be blocked when verified attestation is stale and policy requires freshness.');
        } catch (DatabaseOperationException $e) {
            self::assertSame('verification_failed', $e->failure->value);
            self::assertSame('cancelled', $e->snapshot->outcome);
            self::assertSame(0, $connection->statementCalls);
            $reasons = array_map(
                static fn ($event): ?string => is_object($event) && isset($event->details) && is_array($event->details)
                    ? ($event->details['reason'] ?? null)
                    : null,
                $e->snapshot->events,
            );
            self::assertContains('idempotency_guard_remote_replay_attestation_stale_required', $reasons);
        }
    }

    public function test_runtime_emits_warning_when_active_remote_validation_is_unavailable_and_policy_is_warn(): void
    {
        $this->idempotencyBasePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'voltstack-db-idempotency-runtime-' . uniqid('', true);
        mkdir($this->idempotencyBasePath, 0777, true);

        $store = new DirectoryDatabaseIdempotencyStore($this->idempotencyBasePath . DIRECTORY_SEPARATOR . 'idempotency');
        $validator = new RuntimeRemoteReplayValidatorStub(
            DatabaseRemoteReplayValidationResult::unavailable(
                validator: 'stub-remote-validator',
                message: 'Remote validator temporarily unavailable.',
            ),
        );
        $connection = new RuntimeTestConnection(
            statementQueue: [
                RuntimeTestConnection::statementResult(1),
            ],
        );
        $runtime = new DatabaseOperationRuntime(
            new DatabaseCircuitBreaker(),
            idempotencyStore: $store,
            remoteReplayValidator: $validator,
            idempotencyNodeId: 'node-b',
        );
        $context = DatabaseContext::empty()->withConnection($connection);
        $policy = new DatabaseExecutionPolicy(
            retryLimit: 1,
            retryBackoffMs: 0,
            retryMutationsWhenIdempotent: true,
            idempotencyPendingTtlSeconds: 300,
            remoteReplayAttestationMode: 'require',
            remoteReplayValidationMode: 'warn',
        );
        $plan = $runtime->plan(
            new RawOperation(
                OperationKind::RawExecute,
                'UPDATE users SET active = 1 WHERE id = 1',
                [],
                'primary',
                'mutation-users-remote-validation-warn',
            ),
            $context,
            $policy,
        );

        $record = new DatabaseIdempotencyRecord(
            keyHash: hash('sha256', 'mutation-users-remote-validation-warn'),
            operationFingerprint: $plan->fingerprint,
            requestId: 'req-remote-validation-warn',
            connectionName: 'primary',
            logicalTarget: 'users',
            createdAt: '2026-08-26T07:00:00+00:00',
            nodeId: 'node-a',
            status: 'pending',
            expiresAt: '2099-08-26T07:05:00+00:00',
        );
        $store->acquire($record);
        $confirmationFingerprint = $this->computeConfirmationFingerprintForTest(
            $record,
            'node-a',
            [
                'kind' => 'raw_execute',
                'affected_rows' => 1,
                'rows_read' => 0,
                'outcome' => 'completed',
                'confirmed_at' => '2026-08-26T07:00:05+00:00',
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
            'confirmed_at' => '2026-08-26T07:00:05+00:00',
            'summary_version' => 1,
            'replay_reproducibility' => 'persisted_summary',
            'source_node_id' => 'node-a',
            'evidence_version' => 1,
            'evidence_mode' => 'persisted_evidence',
            'confirmation_fingerprint' => $confirmationFingerprint,
            'attestation_version' => 1,
            'attestation_mode' => 'source_node_self_attested',
            'attested_by_node_id' => 'node-a',
            'attested_at' => '2026-08-26T07:00:05+00:00',
            'attestation_fingerprint' => $this->computeAttestationFingerprintForTest(
                $record,
                'node-a',
                $confirmationFingerprint,
                'source_node_self_attested',
                'node-a',
                '2026-08-26T07:00:05+00:00',
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

        $result = $runtime->execute($plan, $context);
        $reasons = array_map(
            static fn ($event): ?string => is_object($event) && isset($event->details) && is_array($event->details)
                ? ($event->details['reason'] ?? null)
                : null,
            $result->debug['diagnostic']->events ?? [],
        );

        self::assertTrue($result->isSuccess);
        self::assertSame(0, $connection->statementCalls);
        self::assertSame('remote_validation_unavailable', $result->debug['idempotency']['remote_validation']['status'] ?? null);
        self::assertSame('stub-remote-validator', $result->debug['idempotency']['remote_validation']['validator'] ?? null);
        self::assertSame('remote_validation_unavailable', $result->debug['idempotency']['record']['confirmation']['remote_validation_receipt']['status'] ?? null);
        self::assertSame('node-b', $result->debug['idempotency']['record']['confirmation']['remote_validation_receipt']['validated_by_node_id'] ?? null);
        $persisted = $store->find(hash('sha256', 'mutation-users-remote-validation-warn'));
        self::assertInstanceOf(DatabaseIdempotencyRecord::class, $persisted);
        self::assertSame('remote_validation_unavailable', $persisted->confirmation['remote_validation_receipt']['status'] ?? null);
        self::assertSame('warn', $persisted->confirmation['remote_validation_receipt']['validation_mode'] ?? null);
        self::assertContains('idempotency_guard_remote_replay_validation_warning', $reasons);
    }

    public function test_runtime_reuses_fresh_remote_validation_receipt_from_current_node(): void
    {
        $this->idempotencyBasePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'voltstack-db-idempotency-runtime-' . uniqid('', true);
        mkdir($this->idempotencyBasePath, 0777, true);

        $store = new DirectoryDatabaseIdempotencyStore($this->idempotencyBasePath . DIRECTORY_SEPARATOR . 'idempotency');
        $validator = new RuntimeRemoteReplayValidatorStub(
            DatabaseRemoteReplayValidationResult::rejected(
                validator: 'stub-remote-validator',
                message: 'Validator should not be called when a fresh receipt is reusable.',
            ),
        );
        $connection = new RuntimeTestConnection(
            statementQueue: [
                RuntimeTestConnection::statementResult(1),
            ],
        );
        $runtime = new DatabaseOperationRuntime(
            new DatabaseCircuitBreaker(),
            idempotencyStore: $store,
            remoteReplayValidator: $validator,
            idempotencyNodeId: 'node-b',
        );
        $context = DatabaseContext::empty()->withConnection($connection);
        $policy = new DatabaseExecutionPolicy(
            retryLimit: 1,
            retryBackoffMs: 0,
            retryMutationsWhenIdempotent: true,
            idempotencyPendingTtlSeconds: 300,
            remoteReplayAttestationMode: 'require',
            remoteReplayValidationMode: 'require',
            remoteReplayValidationReceiptMaxAgeSeconds: 600,
        );
        $plan = $runtime->plan(
            new RawOperation(
                OperationKind::RawExecute,
                'UPDATE users SET active = 1 WHERE id = 1',
                [],
                'primary',
                'mutation-users-remote-validation-receipt-reuse',
            ),
            $context,
            $policy,
        );

        $record = new DatabaseIdempotencyRecord(
            keyHash: hash('sha256', 'mutation-users-remote-validation-receipt-reuse'),
            operationFingerprint: $plan->fingerprint,
            requestId: 'req-remote-validation-receipt-reuse',
            connectionName: 'primary',
            logicalTarget: 'users',
            createdAt: '2026-08-26T07:30:00+00:00',
            nodeId: 'node-a',
            status: 'pending',
            expiresAt: '2099-08-26T07:35:00+00:00',
        );
        $store->acquire($record);
        $confirmationFingerprint = $this->computeConfirmationFingerprintForTest(
            $record,
            'node-a',
            [
                'kind' => 'raw_execute',
                'affected_rows' => 1,
                'rows_read' => 0,
                'outcome' => 'completed',
                'confirmed_at' => '2026-08-26T07:30:05+00:00',
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
        $freshValidatedAt = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format(DATE_ATOM);
        $store->complete($record, [
            'kind' => 'raw_execute',
            'affected_rows' => 1,
            'rows_read' => 0,
            'outcome' => 'completed',
            'confirmed_at' => '2026-08-26T07:30:05+00:00',
            'summary_version' => 1,
            'replay_reproducibility' => 'persisted_summary',
            'source_node_id' => 'node-a',
            'evidence_version' => 1,
            'evidence_mode' => 'persisted_evidence',
            'confirmation_fingerprint' => $confirmationFingerprint,
            'attestation_version' => 1,
            'attestation_mode' => 'source_node_self_attested',
            'attested_by_node_id' => 'node-a',
            'attested_at' => '2026-08-26T07:30:05+00:00',
            'attestation_fingerprint' => $this->computeAttestationFingerprintForTest(
                $record,
                'node-a',
                $confirmationFingerprint,
                'source_node_self_attested',
                'node-a',
                '2026-08-26T07:30:05+00:00',
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
                'validated_at' => $freshValidatedAt,
                'validated_by_node_id' => 'node-b',
                'source_node_id' => 'node-a',
                'details' => [],
            ],
        ]);

        $result = $runtime->execute($plan, $context);
        $persisted = $store->find(hash('sha256', 'mutation-users-remote-validation-receipt-reuse'));

        self::assertTrue($result->isSuccess);
        self::assertSame(0, $connection->statementCalls);
        self::assertSame(0, $validator->calls);
        self::assertSame('cached_remote_validation_receipt', $result->debug['idempotency']['remote_validation']['validator'] ?? null);
        self::assertSame('reused_fresh_receipt', $result->debug['idempotency']['remote_validation']['details']['receipt_reuse'] ?? null);
        self::assertInstanceOf(DatabaseIdempotencyRecord::class, $persisted);
        self::assertSame('remote-validator-original', $persisted->confirmation['remote_validation_receipt']['validator'] ?? null);
        self::assertSame($freshValidatedAt, $persisted->confirmation['remote_validation_receipt']['validated_at'] ?? null);
    }

    public function test_runtime_falls_back_to_active_remote_validation_when_cached_receipt_is_stale(): void
    {
        $this->idempotencyBasePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'voltstack-db-idempotency-runtime-' . uniqid('', true);
        mkdir($this->idempotencyBasePath, 0777, true);

        $store = new DirectoryDatabaseIdempotencyStore($this->idempotencyBasePath . DIRECTORY_SEPARATOR . 'idempotency');
        $validator = new RuntimeRemoteReplayValidatorStub(
            DatabaseRemoteReplayValidationResult::verified(
                validator: 'stub-remote-validator',
                message: 'Remote validator revalidated stale receipt.',
            ),
        );
        $connection = new RuntimeTestConnection(
            statementQueue: [
                RuntimeTestConnection::statementResult(1),
            ],
        );
        $runtime = new DatabaseOperationRuntime(
            new DatabaseCircuitBreaker(),
            idempotencyStore: $store,
            remoteReplayValidator: $validator,
            idempotencyNodeId: 'node-b',
        );
        $context = DatabaseContext::empty()->withConnection($connection);
        $policy = new DatabaseExecutionPolicy(
            retryLimit: 1,
            retryBackoffMs: 0,
            retryMutationsWhenIdempotent: true,
            idempotencyPendingTtlSeconds: 300,
            remoteReplayAttestationMode: 'require',
            remoteReplayValidationMode: 'require',
            remoteReplayValidationReceiptMaxAgeSeconds: 60,
        );
        $plan = $runtime->plan(
            new RawOperation(
                OperationKind::RawExecute,
                'UPDATE users SET active = 1 WHERE id = 1',
                [],
                'primary',
                'mutation-users-remote-validation-receipt-stale',
            ),
            $context,
            $policy,
        );

        $record = new DatabaseIdempotencyRecord(
            keyHash: hash('sha256', 'mutation-users-remote-validation-receipt-stale'),
            operationFingerprint: $plan->fingerprint,
            requestId: 'req-remote-validation-receipt-stale',
            connectionName: 'primary',
            logicalTarget: 'users',
            createdAt: '2026-08-26T07:45:00+00:00',
            nodeId: 'node-a',
            status: 'pending',
            expiresAt: '2099-08-26T07:50:00+00:00',
        );
        $store->acquire($record);
        $confirmationFingerprint = $this->computeConfirmationFingerprintForTest(
            $record,
            'node-a',
            [
                'kind' => 'raw_execute',
                'affected_rows' => 1,
                'rows_read' => 0,
                'outcome' => 'completed',
                'confirmed_at' => '2026-08-26T07:45:05+00:00',
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
            'confirmed_at' => '2026-08-26T07:45:05+00:00',
            'summary_version' => 1,
            'replay_reproducibility' => 'persisted_summary',
            'source_node_id' => 'node-a',
            'evidence_version' => 1,
            'evidence_mode' => 'persisted_evidence',
            'confirmation_fingerprint' => $confirmationFingerprint,
            'attestation_version' => 1,
            'attestation_mode' => 'source_node_self_attested',
            'attested_by_node_id' => 'node-a',
            'attested_at' => '2026-08-26T07:45:05+00:00',
            'attestation_fingerprint' => $this->computeAttestationFingerprintForTest(
                $record,
                'node-a',
                $confirmationFingerprint,
                'source_node_self_attested',
                'node-a',
                '2026-08-26T07:45:05+00:00',
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
                'message' => 'Old validation that must be refreshed.',
                'validation_mode' => 'require',
                'validated_at' => '2026-08-01T00:00:00+00:00',
                'validated_by_node_id' => 'node-b',
                'source_node_id' => 'node-a',
                'details' => [],
            ],
        ]);

        $result = $runtime->execute($plan, $context);
        $persisted = $store->find(hash('sha256', 'mutation-users-remote-validation-receipt-stale'));

        self::assertTrue($result->isSuccess);
        self::assertSame(0, $connection->statementCalls);
        self::assertSame(1, $validator->calls);
        self::assertSame('stub-remote-validator', $result->debug['idempotency']['remote_validation']['validator'] ?? null);
        self::assertInstanceOf(DatabaseIdempotencyRecord::class, $persisted);
        self::assertSame('stub-remote-validator', $persisted->confirmation['remote_validation_receipt']['validator'] ?? null);
        self::assertSame('require', $persisted->confirmation['remote_validation_receipt']['validation_mode'] ?? null);
    }

    public function test_runtime_blocks_remote_replay_when_active_remote_validation_is_required_but_unavailable(): void
    {
        $this->idempotencyBasePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'voltstack-db-idempotency-runtime-' . uniqid('', true);
        mkdir($this->idempotencyBasePath, 0777, true);

        $store = new DirectoryDatabaseIdempotencyStore($this->idempotencyBasePath . DIRECTORY_SEPARATOR . 'idempotency');
        $validator = new RuntimeRemoteReplayValidatorStub(
            DatabaseRemoteReplayValidationResult::unavailable(
                validator: 'stub-remote-validator',
                message: 'Remote validator temporarily unavailable.',
            ),
        );
        $connection = new RuntimeTestConnection(
            statementQueue: [
                RuntimeTestConnection::statementResult(1),
            ],
        );
        $runtime = new DatabaseOperationRuntime(
            new DatabaseCircuitBreaker(),
            idempotencyStore: $store,
            remoteReplayValidator: $validator,
            idempotencyNodeId: 'node-b',
        );
        $context = DatabaseContext::empty()->withConnection($connection);
        $policy = new DatabaseExecutionPolicy(
            retryLimit: 1,
            retryBackoffMs: 0,
            retryMutationsWhenIdempotent: true,
            idempotencyPendingTtlSeconds: 300,
            remoteReplayAttestationMode: 'require',
            remoteReplayValidationMode: 'require',
        );
        $plan = $runtime->plan(
            new RawOperation(
                OperationKind::RawExecute,
                'UPDATE users SET active = 1 WHERE id = 1',
                [],
                'primary',
                'mutation-users-remote-validation-require',
            ),
            $context,
            $policy,
        );

        $record = new DatabaseIdempotencyRecord(
            keyHash: hash('sha256', 'mutation-users-remote-validation-require'),
            operationFingerprint: $plan->fingerprint,
            requestId: 'req-remote-validation-require',
            connectionName: 'primary',
            logicalTarget: 'users',
            createdAt: '2026-08-26T08:00:00+00:00',
            nodeId: 'node-a',
            status: 'pending',
            expiresAt: '2099-08-26T08:05:00+00:00',
        );
        $store->acquire($record);
        $confirmationFingerprint = $this->computeConfirmationFingerprintForTest(
            $record,
            'node-a',
            [
                'kind' => 'raw_execute',
                'affected_rows' => 1,
                'rows_read' => 0,
                'outcome' => 'completed',
                'confirmed_at' => '2026-08-26T08:00:05+00:00',
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
            'confirmed_at' => '2026-08-26T08:00:05+00:00',
            'summary_version' => 1,
            'replay_reproducibility' => 'persisted_summary',
            'source_node_id' => 'node-a',
            'evidence_version' => 1,
            'evidence_mode' => 'persisted_evidence',
            'confirmation_fingerprint' => $confirmationFingerprint,
            'attestation_version' => 1,
            'attestation_mode' => 'source_node_self_attested',
            'attested_by_node_id' => 'node-a',
            'attested_at' => '2026-08-26T08:00:05+00:00',
            'attestation_fingerprint' => $this->computeAttestationFingerprintForTest(
                $record,
                'node-a',
                $confirmationFingerprint,
                'source_node_self_attested',
                'node-a',
                '2026-08-26T08:00:05+00:00',
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

        try {
            $runtime->execute($plan, $context);
            self::fail('Remote replay should be blocked when active remote validation is required but unavailable.');
        } catch (DatabaseOperationException $e) {
            self::assertSame('verification_failed', $e->failure->value);
            self::assertSame('cancelled', $e->snapshot->outcome);
            self::assertSame(0, $connection->statementCalls);
            $reasons = array_map(
                static fn ($event): ?string => is_object($event) && isset($event->details) && is_array($event->details)
                    ? ($event->details['reason'] ?? null)
                    : null,
                $e->snapshot->events,
            );
            self::assertContains('idempotency_guard_remote_replay_validation_required', $reasons);
        }
    }

    public function test_runtime_blocks_remote_replay_when_active_remote_validation_rejects_it(): void
    {
        $this->idempotencyBasePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'voltstack-db-idempotency-runtime-' . uniqid('', true);
        mkdir($this->idempotencyBasePath, 0777, true);

        $store = new DirectoryDatabaseIdempotencyStore($this->idempotencyBasePath . DIRECTORY_SEPARATOR . 'idempotency');
        $validator = new RuntimeRemoteReplayValidatorStub(
            DatabaseRemoteReplayValidationResult::rejected(
                validator: 'stub-remote-validator',
                message: 'Remote node rejected replay verification.',
                details: ['challenge' => 'nonce-123'],
            ),
        );
        $connection = new RuntimeTestConnection(
            statementQueue: [
                RuntimeTestConnection::statementResult(1),
            ],
        );
        $runtime = new DatabaseOperationRuntime(
            new DatabaseCircuitBreaker(),
            idempotencyStore: $store,
            remoteReplayValidator: $validator,
            idempotencyNodeId: 'node-b',
        );
        $context = DatabaseContext::empty()->withConnection($connection);
        $policy = new DatabaseExecutionPolicy(
            retryLimit: 1,
            retryBackoffMs: 0,
            retryMutationsWhenIdempotent: true,
            idempotencyPendingTtlSeconds: 300,
            remoteReplayAttestationMode: 'require',
            remoteReplayValidationMode: 'allow',
        );
        $plan = $runtime->plan(
            new RawOperation(
                OperationKind::RawExecute,
                'UPDATE users SET active = 1 WHERE id = 1',
                [],
                'primary',
                'mutation-users-remote-validation-rejected',
            ),
            $context,
            $policy,
        );

        $record = new DatabaseIdempotencyRecord(
            keyHash: hash('sha256', 'mutation-users-remote-validation-rejected'),
            operationFingerprint: $plan->fingerprint,
            requestId: 'req-remote-validation-rejected',
            connectionName: 'primary',
            logicalTarget: 'users',
            createdAt: '2026-08-26T09:00:00+00:00',
            nodeId: 'node-a',
            status: 'pending',
            expiresAt: '2099-08-26T09:05:00+00:00',
        );
        $store->acquire($record);
        $confirmationFingerprint = $this->computeConfirmationFingerprintForTest(
            $record,
            'node-a',
            [
                'kind' => 'raw_execute',
                'affected_rows' => 1,
                'rows_read' => 0,
                'outcome' => 'completed',
                'confirmed_at' => '2026-08-26T09:00:05+00:00',
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
            'confirmed_at' => '2026-08-26T09:00:05+00:00',
            'summary_version' => 1,
            'replay_reproducibility' => 'persisted_summary',
            'source_node_id' => 'node-a',
            'evidence_version' => 1,
            'evidence_mode' => 'persisted_evidence',
            'confirmation_fingerprint' => $confirmationFingerprint,
            'attestation_version' => 1,
            'attestation_mode' => 'source_node_self_attested',
            'attested_by_node_id' => 'node-a',
            'attested_at' => '2026-08-26T09:00:05+00:00',
            'attestation_fingerprint' => $this->computeAttestationFingerprintForTest(
                $record,
                'node-a',
                $confirmationFingerprint,
                'source_node_self_attested',
                'node-a',
                '2026-08-26T09:00:05+00:00',
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

        try {
            $runtime->execute($plan, $context);
            self::fail('Remote replay should be blocked when active remote validation rejects it.');
        } catch (DatabaseOperationException $e) {
            self::assertSame('verification_failed', $e->failure->value);
            self::assertSame('cancelled', $e->snapshot->outcome);
            self::assertSame(0, $connection->statementCalls);
            $reasons = array_map(
                static fn ($event): ?string => is_object($event) && isset($event->details) && is_array($event->details)
                    ? ($event->details['reason'] ?? null)
                    : null,
                $e->snapshot->events,
            );
            self::assertContains('idempotency_guard_remote_replay_validation_rejected', $reasons);
        }
    }

    public function test_runtime_short_circuits_confirmed_replay_without_touching_database(): void
    {
        $this->idempotencyBasePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'voltstack-db-idempotency-runtime-' . uniqid('', true);
        mkdir($this->idempotencyBasePath, 0777, true);

        $store = new DirectoryDatabaseIdempotencyStore($this->idempotencyBasePath . DIRECTORY_SEPARATOR . 'idempotency');
        $seed = new DatabaseIdempotencyRecord(
            keyHash: hash('sha256', 'mutation-users-replay'),
            operationFingerprint: 'raw:primary:update-users-replay',
            requestId: 'req-replay-a',
            connectionName: 'primary',
            logicalTarget: 'users',
            createdAt: '2026-08-25T00:00:00+00:00',
            nodeId: 'node-a',
            status: 'pending',
            expiresAt: '2099-08-25T00:05:00+00:00',
        );
        $store->acquire($seed);
        $store->complete($seed);

        $connection = new RuntimeTestConnection(
            statementQueue: [
                RuntimeTestConnection::statementResult(1),
            ],
        );
        $runtime = new DatabaseOperationRuntime(
            new DatabaseCircuitBreaker(),
            idempotencyStore: $store,
            idempotencyNodeId: 'node-b',
        );
        $context = DatabaseContext::empty()->withConnection($connection);
        $policy = new DatabaseExecutionPolicy(
            retryLimit: 1,
            retryBackoffMs: 0,
            retryMutationsWhenIdempotent: true,
            idempotencyPendingTtlSeconds: 300,
        );
        $plan = $runtime->plan(
            new RawOperation(
                OperationKind::RawExecute,
                'UPDATE users SET active = 1 WHERE id = 1',
                [],
                'primary',
                'mutation-users-replay',
            ),
            $context,
            $policy,
        );

        $confirmed = new DatabaseIdempotencyRecord(
            keyHash: hash('sha256', 'mutation-users-replay'),
            operationFingerprint: $plan->fingerprint,
            requestId: 'req-replay-a',
            connectionName: 'primary',
            logicalTarget: 'users',
            createdAt: '2026-08-25T00:00:00+00:00',
            nodeId: 'node-a',
            status: 'pending',
            expiresAt: '2099-08-25T00:05:00+00:00',
        );
        $store->complete($confirmed, [
            'kind' => 'raw_execute',
            'affected_rows' => 1,
            'rows_read' => 0,
            'outcome' => 'completed',
            'confirmed_at' => '2026-08-25T00:00:05+00:00',
        ]);

        $result = $runtime->execute($plan, $context);

        self::assertTrue($result->isSuccess);
        self::assertSame(0, $connection->statementCalls);
        self::assertSame(1, $result->affectedRows);
        self::assertSame('replayed_confirmed', $result->debug['idempotency']['status'] ?? null);
        self::assertSame('idempotency_confirmation', $result->debug['idempotency']['source'] ?? null);
        self::assertSame('completed', $result->debug['idempotency']['record']['status'] ?? null);
        self::assertSame('completed', $result->debug['idempotency']['confirmation']['outcome'] ?? null);
        self::assertSame('legacy_reconstructed', $result->debug['idempotency']['replay_reproducibility'] ?? null);
        self::assertSame('node-b', $result->debug['idempotency']['current_node_id'] ?? null);
        self::assertSame('node-a', $result->debug['idempotency']['source_node_id'] ?? null);
        self::assertSame('federated_remote_node', $result->debug['idempotency']['replay_origin'] ?? null);
        self::assertSame('legacy_reconstructed_evidence', $result->debug['idempotency']['confirmation_evidence']['evidence_mode'] ?? null);
        self::assertSame('node-a', $result->debug['idempotency']['confirmation_evidence']['source_node_id'] ?? null);
        self::assertIsString($result->debug['idempotency']['confirmation_evidence']['confirmation_fingerprint'] ?? null);
        self::assertSame('reconstructed_legacy_evidence', $result->debug['idempotency']['confirmation_evidence']['verification_status'] ?? null);
        self::assertSame('legacy_reconstructed', $result->debug['idempotency']['confirmation_evidence']['trust_level'] ?? null);
        self::assertSame('legacy_reconstructed', $result->debug['idempotency']['evidence_trust_level'] ?? null);
        self::assertSame('success_no_rows', $result->debug['idempotency']['result_summary']['result_type'] ?? null);
        self::assertSame(1, $result->debug['idempotency']['result_summary']['affected_rows'] ?? null);
        self::assertSame(1, $result->debug['diagnostic']->affectedRows ?? null);
        $completedEvents = array_values(array_filter(
            $result->debug['diagnostic']->events ?? [],
            static fn ($event): bool => is_object($event)
                && isset($event->details)
                && is_array($event->details)
                && (($event->details['reason'] ?? null) === 'idempotency_guard_replayed_confirmed'),
        ));
        self::assertNotEmpty($completedEvents);
        self::assertSame('federated_remote_node', $completedEvents[0]->details['replay_origin'] ?? null);
    }

    public function test_runtime_can_block_legacy_confirmed_replay_when_policy_requires_it(): void
    {
        $this->idempotencyBasePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'voltstack-db-idempotency-runtime-' . uniqid('', true);
        mkdir($this->idempotencyBasePath, 0777, true);

        $store = new DirectoryDatabaseIdempotencyStore($this->idempotencyBasePath . DIRECTORY_SEPARATOR . 'idempotency');
        $connection = new RuntimeTestConnection(
            statementQueue: [
                RuntimeTestConnection::statementResult(1),
            ],
        );
        $runtime = new DatabaseOperationRuntime(
            new DatabaseCircuitBreaker(),
            idempotencyStore: $store,
            idempotencyNodeId: 'node-b',
        );
        $context = DatabaseContext::empty()->withConnection($connection);
        $policy = new DatabaseExecutionPolicy(
            retryLimit: 1,
            retryBackoffMs: 0,
            retryMutationsWhenIdempotent: true,
            idempotencyPendingTtlSeconds: 300,
            legacyReplayMode: 'block',
        );
        $plan = $runtime->plan(
            new RawOperation(
                OperationKind::RawExecute,
                'UPDATE users SET active = 1 WHERE id = 1',
                [],
                'primary',
                'mutation-users-legacy-blocked',
            ),
            $context,
            $policy,
        );

        $legacy = new DatabaseIdempotencyRecord(
            keyHash: hash('sha256', 'mutation-users-legacy-blocked'),
            operationFingerprint: $plan->fingerprint,
            requestId: 'req-legacy-blocked',
            connectionName: 'primary',
            logicalTarget: 'users',
            createdAt: '2026-08-25T00:00:00+00:00',
            nodeId: 'node-a',
            status: 'pending',
            expiresAt: '2099-08-25T00:05:00+00:00',
        );
        $store->acquire($legacy);
        $store->complete($legacy, [
            'kind' => 'raw_execute',
            'affected_rows' => 1,
            'rows_read' => 0,
            'outcome' => 'completed',
            'confirmed_at' => '2026-08-25T00:00:05+00:00',
        ]);

        try {
            $runtime->execute($plan, $context);
            self::fail('Legacy replay should be blocked when policy mode is block.');
        } catch (DatabaseOperationException $e) {
            self::assertSame('verification_failed', $e->failure->value);
            self::assertSame('cancelled', $e->snapshot->outcome);
            self::assertSame(0, $connection->statementCalls);
            $reasons = array_map(
                static fn ($event): ?string => is_object($event) && isset($event->details) && is_array($event->details)
                    ? ($event->details['reason'] ?? null)
                    : null,
                $e->snapshot->events,
            );
            self::assertContains('idempotency_guard_legacy_replay_blocked', $reasons);
        }
    }

    public function test_runtime_emits_warning_for_legacy_confirmed_replay_when_policy_is_warn(): void
    {
        $this->idempotencyBasePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'voltstack-db-idempotency-runtime-' . uniqid('', true);
        mkdir($this->idempotencyBasePath, 0777, true);

        $store = new DirectoryDatabaseIdempotencyStore($this->idempotencyBasePath . DIRECTORY_SEPARATOR . 'idempotency');
        $connection = new RuntimeTestConnection(
            statementQueue: [
                RuntimeTestConnection::statementResult(1),
            ],
        );
        $runtime = new DatabaseOperationRuntime(
            new DatabaseCircuitBreaker(),
            idempotencyStore: $store,
            idempotencyNodeId: 'node-b',
        );
        $context = DatabaseContext::empty()->withConnection($connection);
        $policy = new DatabaseExecutionPolicy(
            retryLimit: 1,
            retryBackoffMs: 0,
            retryMutationsWhenIdempotent: true,
            idempotencyPendingTtlSeconds: 300,
            legacyReplayMode: 'warn',
        );
        $plan = $runtime->plan(
            new RawOperation(
                OperationKind::RawExecute,
                'UPDATE users SET active = 1 WHERE id = 1',
                [],
                'primary',
                'mutation-users-legacy-warn',
            ),
            $context,
            $policy,
        );

        $legacy = new DatabaseIdempotencyRecord(
            keyHash: hash('sha256', 'mutation-users-legacy-warn'),
            operationFingerprint: $plan->fingerprint,
            requestId: 'req-legacy-warn',
            connectionName: 'primary',
            logicalTarget: 'users',
            createdAt: '2026-08-25T00:00:00+00:00',
            nodeId: 'node-a',
            status: 'pending',
            expiresAt: '2099-08-25T00:05:00+00:00',
        );
        $store->acquire($legacy);
        $store->complete($legacy, [
            'kind' => 'raw_execute',
            'affected_rows' => 1,
            'rows_read' => 0,
            'outcome' => 'completed',
            'confirmed_at' => '2026-08-25T00:00:05+00:00',
        ]);

        $result = $runtime->execute($plan, $context);
        $warning = $result->debug['idempotency']['warning'] ?? null;
        $reasons = array_map(
            static fn ($event): ?string => is_object($event) && isset($event->details) && is_array($event->details)
                ? ($event->details['reason'] ?? null)
                : null,
            $result->debug['diagnostic']->events ?? [],
        );

        self::assertTrue($result->isSuccess);
        self::assertSame(0, $connection->statementCalls);
        self::assertSame('replayed_confirmed', $result->debug['idempotency']['status'] ?? null);
        self::assertSame('legacy_reconstructed', $result->debug['idempotency']['replay_reproducibility'] ?? null);
        self::assertIsArray($warning);
        self::assertSame('idempotency_guard_legacy_replay_warning', $warning['reason'] ?? null);
        self::assertSame('warn', $warning['legacy_replay_mode'] ?? null);
        self::assertSame('federated_remote_node', $warning['replay_origin'] ?? null);
        self::assertSame('node-b', $warning['current_node_id'] ?? null);
        self::assertSame('legacy_reconstructed_evidence', $warning['confirmation_evidence_mode'] ?? null);
        self::assertSame('reconstructed_legacy_evidence', $warning['verification_status'] ?? null);
        self::assertSame('legacy_reconstructed', $warning['evidence_trust_level'] ?? null);
        self::assertIsString($warning['confirmation_fingerprint'] ?? null);
        self::assertContains('idempotency_guard_legacy_replay_warning', $reasons);
        self::assertContains('idempotency_guard_replayed_confirmed', $reasons);
    }

    public function test_runtime_blocks_replay_when_persisted_confirmation_evidence_is_inconsistent(): void
    {
        $this->idempotencyBasePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'voltstack-db-idempotency-runtime-' . uniqid('', true);
        mkdir($this->idempotencyBasePath, 0777, true);

        $store = new DirectoryDatabaseIdempotencyStore($this->idempotencyBasePath . DIRECTORY_SEPARATOR . 'idempotency');
        $connection = new RuntimeTestConnection(
            statementQueue: [
                RuntimeTestConnection::statementResult(1),
            ],
        );
        $runtime = new DatabaseOperationRuntime(
            new DatabaseCircuitBreaker(),
            idempotencyStore: $store,
            idempotencyNodeId: 'node-b',
        );
        $context = DatabaseContext::empty()->withConnection($connection);
        $policy = new DatabaseExecutionPolicy(
            retryLimit: 1,
            retryBackoffMs: 0,
            retryMutationsWhenIdempotent: true,
            idempotencyPendingTtlSeconds: 300,
        );
        $plan = $runtime->plan(
            new RawOperation(
                OperationKind::RawExecute,
                'UPDATE users SET active = 1 WHERE id = 1',
                [],
                'primary',
                'mutation-users-evidence-mismatch',
            ),
            $context,
            $policy,
        );

        $record = new DatabaseIdempotencyRecord(
            keyHash: hash('sha256', 'mutation-users-evidence-mismatch'),
            operationFingerprint: $plan->fingerprint,
            requestId: 'req-evidence-mismatch',
            connectionName: 'primary',
            logicalTarget: 'users',
            createdAt: '2026-08-26T00:00:00+00:00',
            nodeId: 'node-a',
            status: 'pending',
            expiresAt: '2099-08-26T00:05:00+00:00',
        );
        $store->acquire($record);
        $store->complete($record, [
            'kind' => 'raw_execute',
            'affected_rows' => 1,
            'rows_read' => 0,
            'outcome' => 'completed',
            'confirmed_at' => '2026-08-26T00:00:05+00:00',
            'summary_version' => 1,
            'replay_reproducibility' => 'persisted_summary',
            'source_node_id' => 'node-a',
            'evidence_version' => 1,
            'evidence_mode' => 'persisted_evidence',
            'confirmation_fingerprint' => 'mismatch-fingerprint',
            'result_summary' => [
                'kind' => 'raw_execute',
                'is_select' => false,
                'affected_rows' => 1,
                'rows_read' => 0,
                'column_count' => 0,
                'result_type' => 'success_no_rows',
            ],
        ]);

        try {
            $runtime->execute($plan, $context);
            self::fail('Replay should be blocked when persisted confirmation evidence does not verify.');
        } catch (DatabaseOperationException $e) {
            self::assertSame('verification_failed', $e->failure->value);
            self::assertSame('cancelled', $e->snapshot->outcome);
            self::assertSame(0, $connection->statementCalls);
            $reasons = array_map(
                static fn ($event): ?string => is_object($event) && isset($event->details) && is_array($event->details)
                    ? ($event->details['reason'] ?? null)
                    : null,
                $e->snapshot->events,
            );
            self::assertContains('idempotency_guard_confirmation_evidence_mismatch', $reasons);
            $mismatchEvents = array_values(array_filter(
                $e->snapshot->events,
                static fn ($event): bool => is_object($event)
                    && isset($event->details)
                    && is_array($event->details)
                    && (($event->details['reason'] ?? null) === 'idempotency_guard_confirmation_evidence_mismatch'),
            ));
            self::assertNotEmpty($mismatchEvents);
            self::assertSame('untrusted_mismatch', $mismatchEvents[0]->details['evidence_trust_level'] ?? null);
        }
    }

    public function test_runtime_blocks_replay_when_source_node_attestation_is_inconsistent(): void
    {
        $this->idempotencyBasePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'voltstack-db-idempotency-runtime-' . uniqid('', true);
        mkdir($this->idempotencyBasePath, 0777, true);

        $store = new DirectoryDatabaseIdempotencyStore($this->idempotencyBasePath . DIRECTORY_SEPARATOR . 'idempotency');
        $connection = new RuntimeTestConnection(
            statementQueue: [
                RuntimeTestConnection::statementResult(1),
            ],
        );
        $runtime = new DatabaseOperationRuntime(
            new DatabaseCircuitBreaker(),
            idempotencyStore: $store,
            idempotencyNodeId: 'node-b',
        );
        $context = DatabaseContext::empty()->withConnection($connection);
        $policy = new DatabaseExecutionPolicy(
            retryLimit: 1,
            retryBackoffMs: 0,
            retryMutationsWhenIdempotent: true,
            idempotencyPendingTtlSeconds: 300,
        );
        $plan = $runtime->plan(
            new RawOperation(
                OperationKind::RawExecute,
                'UPDATE users SET active = 1 WHERE id = 1',
                [],
                'primary',
                'mutation-users-attestation-mismatch',
            ),
            $context,
            $policy,
        );

        $record = new DatabaseIdempotencyRecord(
            keyHash: hash('sha256', 'mutation-users-attestation-mismatch'),
            operationFingerprint: $plan->fingerprint,
            requestId: 'req-attestation-mismatch',
            connectionName: 'primary',
            logicalTarget: 'users',
            createdAt: '2026-08-26T02:00:00+00:00',
            nodeId: 'node-a',
            status: 'pending',
            expiresAt: '2099-08-26T02:05:00+00:00',
        );
        $store->acquire($record);
        $confirmationFingerprint = $this->computeConfirmationFingerprintForTest(
            $record,
            'node-a',
            [
                'kind' => 'raw_execute',
                'affected_rows' => 1,
                'rows_read' => 0,
                'outcome' => 'completed',
                'confirmed_at' => '2026-08-26T02:00:05+00:00',
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
            'confirmed_at' => '2026-08-26T02:00:05+00:00',
            'summary_version' => 1,
            'replay_reproducibility' => 'persisted_summary',
            'source_node_id' => 'node-a',
            'evidence_version' => 1,
            'evidence_mode' => 'persisted_evidence',
            'confirmation_fingerprint' => $confirmationFingerprint,
            'attestation_version' => 1,
            'attestation_mode' => 'source_node_self_attested',
            'attested_by_node_id' => 'node-a',
            'attested_at' => '2026-08-26T02:00:05+00:00',
            'attestation_fingerprint' => 'mismatch-attestation',
            'result_summary' => [
                'kind' => 'raw_execute',
                'is_select' => false,
                'affected_rows' => 1,
                'rows_read' => 0,
                'column_count' => 0,
                'result_type' => 'success_no_rows',
            ],
        ]);

        try {
            $runtime->execute($plan, $context);
            self::fail('Replay should be blocked when source node attestation does not verify.');
        } catch (DatabaseOperationException $e) {
            self::assertSame('verification_failed', $e->failure->value);
            self::assertSame('cancelled', $e->snapshot->outcome);
            self::assertSame(0, $connection->statementCalls);
            $reasons = array_map(
                static fn ($event): ?string => is_object($event) && isset($event->details) && is_array($event->details)
                    ? ($event->details['reason'] ?? null)
                    : null,
                $e->snapshot->events,
            );
            self::assertContains('idempotency_guard_source_node_attestation_mismatch', $reasons);
        }
    }

    public function test_runtime_opens_circuit_after_repeated_transient_failures(): void
    {
        $connection = new RuntimeTestConnection([
            DbalException::wrap(new \RuntimeException('temporary disconnect 1'), DatabaseFailureKind::Connectivity, 'stmt.execute', 'SELECT 1', true),
            DbalException::wrap(new \RuntimeException('temporary disconnect 2'), DatabaseFailureKind::Connectivity, 'stmt.execute', 'SELECT 1', true),
        ]);
        $runtime = new DatabaseOperationRuntime(new DatabaseCircuitBreaker());
        $context = DatabaseContext::empty()->withConnection($connection);
        $policy = new DatabaseExecutionPolicy(retryLimit: 0, retryBackoffMs: 0, circuitFailureThreshold: 2, circuitCooldownMs: 60000);
        $plan = $runtime->plan(new RawOperation(OperationKind::RawQuery, 'SELECT 1', [], 'primary'), $context, $policy);

        try {
            $runtime->execute($plan, $context);
            self::fail('First transient failure should raise an exception.');
        } catch (DatabaseOperationException $e) {
            self::assertSame('transient', $e->failure->value);
        }

        try {
            $runtime->execute($plan, $context);
            self::fail('Second transient failure should raise an exception.');
        } catch (DatabaseOperationException $e) {
            self::assertSame('transient', $e->failure->value);
        }

        try {
            $runtime->execute($plan, $context);
            self::fail('Open circuit should block the third attempt.');
        } catch (DatabaseOperationException $e) {
            self::assertSame('transient', $e->failure->value);
            self::assertSame('open', $e->snapshot->circuitState);
        }

        self::assertSame(2, $connection->queryCalls);
    }

    public function test_runtime_segments_circuit_by_logical_target_and_records_telemetry(): void
    {
        $connection = new RuntimeTestConnection([
            DbalException::wrap(new \RuntimeException('temporary users disconnect'), DatabaseFailureKind::Connectivity, 'stmt.execute', 'SELECT * FROM users', true),
            DbalException::wrap(new \RuntimeException('temporary posts disconnect'), DatabaseFailureKind::Connectivity, 'stmt.execute', 'SELECT * FROM posts', true),
            RuntimeTestConnection::queryResult([
                ['id' => 1],
            ]),
        ]);
        $telemetry = new DatabaseTelemetryStore();
        $runtime = new DatabaseOperationRuntime(new DatabaseCircuitBreaker(), $telemetry);
        $context = DatabaseContext::empty()->withConnection($connection);
        $policy = new DatabaseExecutionPolicy(retryLimit: 0, retryBackoffMs: 0, circuitFailureThreshold: 2, circuitCooldownMs: 60000);
        $usersPlan = $runtime->plan(new RawOperation(OperationKind::RawQuery, 'SELECT * FROM users', [], 'primary'), $context, $policy);
        $postsPlan = $runtime->plan(new RawOperation(OperationKind::RawQuery, 'SELECT * FROM posts', [], 'primary'), $context, $policy);

        try {
            $runtime->execute($usersPlan, $context);
            self::fail('First users failure should raise an exception.');
        } catch (DatabaseOperationException) {
        }

        try {
            $runtime->execute($postsPlan, $context);
            self::fail('First posts failure should raise an exception.');
        } catch (DatabaseOperationException) {
        }

        $result = $runtime->execute($usersPlan, $context);

        self::assertTrue($result->isSuccess);
        self::assertSame('users', $usersPlan->logicalTarget);
        self::assertSame('posts', $postsPlan->logicalTarget);
        self::assertNotSame($usersPlan->circuitSegment, $postsPlan->circuitSegment);
        self::assertSame(3, $connection->queryCalls);

        $summary = $telemetry->summary();
        $health = $telemetry->health();

        self::assertSame(3, $summary['total_operations']);
        self::assertSame(1, $summary['completed']);
        self::assertSame(2, $summary['failed']);
        self::assertSame(2, $health->totalSegments);
        self::assertSame(2, $health->closedSegments);
    }

    public function test_runtime_blocks_mutating_operations_when_fallback_policy_detects_degraded_health(): void
    {
        $connection = new RuntimeTestConnection();
        $telemetry = new DatabaseTelemetryStore();
        $healthStore = new InMemoryDatabaseHealthStore();
        $healthStore->persist(new DatabaseTelemetryReport(
            requestId: 'req-degraded-write',
            tenantId: null,
            traceId: null,
            generatedAt: '2026-08-24T00:00:00+00:00',
            summary: [
                'total_operations' => 1,
                'completed' => 0,
                'failed' => 1,
                'cancelled' => 0,
                'slow_queries' => 0,
            ],
            health: [
                'closed_segments' => 0,
                'half_open_segments' => 0,
                'open_segments' => 1,
                'segments' => [
                    [
                        'segment' => 'runtime-test|sqlite|raw_execute|users',
                        'state' => 'open',
                    ],
                ],
            ],
            nodeId: 'node-a',
        ));

        $runtime = new DatabaseOperationRuntime(new DatabaseCircuitBreaker(), $telemetry, $healthStore);
        $context = DatabaseContext::empty()->withConnection($connection);
        $policy = new DatabaseExecutionPolicy(
            fallbackEnabled: true,
            fallbackMode: 'read_only_when_unhealthy',
            fallbackAggregateLimit: 10,
            fallbackOpenSegmentsThreshold: 1,
            fallbackHalfOpenSegmentsThreshold: 1,
        );
        $plan = $runtime->plan(new RawOperation(OperationKind::RawExecute, 'UPDATE users SET active = 1 WHERE id = 1', [], 'primary'), $context, $policy);

        try {
            $runtime->execute($plan, $context);
            self::fail('Degraded aggregate health should block mutating operations.');
        } catch (DatabaseOperationException $e) {
            self::assertSame('degraded', $e->failure->value);
            self::assertSame('cancelled', $e->snapshot->outcome);
            self::assertSame(0, $e->snapshot->attempts);
            self::assertSame('closed', $e->snapshot->circuitState);
            self::assertSame('fallback_policy_degraded', $e->snapshot->events[1]->details['reason'] ?? null);
        }

        self::assertSame(0, $connection->statementCalls);
        self::assertSame(0, $connection->queryCalls);

        $summary = $telemetry->summary();

        self::assertSame(1, $summary['total_operations']);
        self::assertSame(1, $summary['cancelled']);
        self::assertSame(0, $summary['completed']);
        self::assertSame('degraded', $summary['latest'][0]['failure']);
    }

    public function test_runtime_allows_read_only_operations_during_read_only_fallback_mode(): void
    {
        $connection = new RuntimeTestConnection([
            RuntimeTestConnection::queryResult([
                ['id' => 1],
            ]),
        ]);
        $telemetry = new DatabaseTelemetryStore();
        $healthStore = new InMemoryDatabaseHealthStore();
        $healthStore->persist(new DatabaseTelemetryReport(
            requestId: 'req-degraded-read',
            tenantId: null,
            traceId: null,
            generatedAt: '2026-08-24T00:00:01+00:00',
            summary: [
                'total_operations' => 1,
                'completed' => 0,
                'failed' => 1,
                'cancelled' => 0,
                'slow_queries' => 0,
            ],
            health: [
                'closed_segments' => 0,
                'half_open_segments' => 0,
                'open_segments' => 1,
                'segments' => [
                    [
                        'segment' => 'runtime-test|sqlite|raw_query|users',
                        'state' => 'open',
                    ],
                ],
            ],
            nodeId: 'node-a',
        ));

        $runtime = new DatabaseOperationRuntime(new DatabaseCircuitBreaker(), $telemetry, $healthStore);
        $context = DatabaseContext::empty()->withConnection($connection);
        $policy = new DatabaseExecutionPolicy(
            fallbackEnabled: true,
            fallbackMode: 'read_only_when_unhealthy',
            fallbackAggregateLimit: 10,
            fallbackOpenSegmentsThreshold: 1,
            fallbackHalfOpenSegmentsThreshold: 1,
        );
        $plan = $runtime->plan(new RawOperation(OperationKind::RawQuery, 'SELECT * FROM users', [], 'primary'), $context, $policy);

        $result = $runtime->execute($plan, $context);

        self::assertTrue($result->isSuccess);
        self::assertSame(1, $connection->queryCalls);
        self::assertSame(0, $connection->statementCalls);

        $summary = $telemetry->summary();

        self::assertSame(1, $summary['total_operations']);
        self::assertSame(1, $summary['completed']);
        self::assertSame(0, $summary['cancelled']);
        self::assertSame(null, $summary['latest'][0]['failure']);
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

    /**
     * @param array<string, mixed> $confirmation
     */
    private function computeConfirmationFingerprintForTest(
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

    private function computeAttestationFingerprintForTest(
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
}

final class RuntimeRemoteReplayValidatorStub implements DatabaseRemoteReplayValidatorInterface
{
    public int $calls = 0;

    public function __construct(private readonly DatabaseRemoteReplayValidationResult $result) {}

    public function validate(
        DatabaseIdempotencyRecord $record,
        \Quantum\Database\Operation\DatabaseOperationPlan $plan,
        ?string $currentNodeId,
        array $confirmationEvidence,
    ): DatabaseRemoteReplayValidationResult {
        $this->calls++;

        return $this->result;
    }
}

final class RuntimeTestConnection implements ConnectionInterface
{
    public int $queryCalls = 0;
    public int $statementCalls = 0;
    private bool $connected = false;

    /**
     * @param list<DbalException|QueryResult> $queryQueue
     * @param list<DbalException|QueryResult> $statementQueue
     */
    public function __construct(
        private array $queryQueue = [],
        private array $statementQueue = [],
    ) {
    }

    /**
     * @param list<array<string,mixed>> $rows
     */
    public static function queryResult(array $rows, int $affectedRows = 0): QueryResult
    {
        return new QueryResult(
            isSelect: true,
            affectedRows: $affectedRows,
            columnMeta: [],
            rowGenerator: static function () use ($rows): \Generator {
                foreach ($rows as $row) {
                    yield $row;
                }
            },
            cleanup: static function (): void {},
            columnCount: $rows === [] ? 0 : count(array_keys($rows[0])),
        );
    }

    public static function statementResult(int $affectedRows = 1): QueryResult
    {
        return new QueryResult(
            isSelect: false,
            affectedRows: $affectedRows,
            columnMeta: [],
            rowGenerator: static function (): \Generator {
                if (false) {
                    yield [];
                }
            },
            cleanup: static function (): void {},
            columnCount: 0,
        );
    }

    public function connect(): void
    {
        $this->connected = true;
    }

    public function isConnected(): bool
    {
        return $this->connected;
    }

    public function close(): void
    {
        $this->connected = false;
    }

    public function ping(): bool
    {
        return true;
    }

    public function prepare(string $sql): StatementInterface
    {
        throw new \BadMethodCallException('Not used in this test.');
    }

    public function executeStatement(string $sql, array $params = []): QueryResult
    {
        $this->statementCalls++;

        $next = array_shift($this->statementQueue);

        if ($next instanceof DbalException) {
            throw $next;
        }

        if ($next instanceof QueryResult) {
            return $next;
        }

        return self::statementResult();
    }

    public function executeQuery(string $sql, array $params = []): QueryResult
    {
        $this->queryCalls++;
        $next = array_shift($this->queryQueue);

        if ($next instanceof DbalException) {
            throw $next;
        }

        if ($next instanceof QueryResult) {
            return $next;
        }

        throw new \RuntimeException('Missing scripted query result.');
    }

    public function lastInsertId(?string $sequenceName = null): string|int|null
    {
        return null;
    }

    public function quoteIdentifier(string $identifier): string
    {
        return '"' . $identifier . '"';
    }

    public function quoteString(string $value): string
    {
        return "'" . $value . "'";
    }

    public function inTransaction(): bool
    {
        return false;
    }

    public function beginTransaction(): bool
    {
        return true;
    }

    public function commit(): bool
    {
        return true;
    }

    public function rollback(): bool
    {
        return true;
    }

    public function createSavepoint(string $identifier): void
    {
    }

    public function releaseSavepoint(string $identifier): void
    {
    }

    public function rollbackToSavepoint(string $identifier): void
    {
    }

    public function setTransactionIsolation(TransactionIsolation $level): void
    {
    }

    public function getDriverInfo(): DriverInfo
    {
        return new DriverInfo(
            driverName: 'sqlite',
            serverVersion: 'test',
            databaseName: 'runtime-test',
        );
    }

    public function getCapabilities(): DatabaseCapabilitySet
    {
        return DatabaseCapabilitySet::minimalSet();
    }

    public function lastUsedAtSeconds(): float
    {
        return hrtime(true) / 1_000_000_000;
    }

    public function getNativeHandle(): mixed
    {
        return null;
    }
}
