<?php

declare(strict_types=1);

namespace VoltStack\Test\Unit;

use PHPUnit\Framework\TestCase;
use Quantum\Database\Capability\DatabaseCapabilitySet;
use Quantum\Database\DatabaseContext;
use Quantum\Database\Dbal\Contract\ConnectionInterface;
use Quantum\Database\Dbal\Contract\StatementInterface;
use Quantum\Database\Dbal\Enum\TransactionIsolation;
use Quantum\Database\Dbal\Value\DriverInfo;
use Quantum\Database\Dbal\Value\QueryResult;
use Quantum\Database\Operation\Contracts\DatabaseRemoteReplayChallengerInterface;
use Quantum\Database\Operation\DatabaseCircuitBreaker;
use Quantum\Database\Operation\DatabaseExecutionPolicy;
use Quantum\Database\Operation\DatabaseIdempotencyRecord;
use Quantum\Database\Operation\DatabaseOperationRuntime;
use Quantum\Database\Operation\DatabaseRemoteReplayChallengeRequest;
use Quantum\Database\Operation\DatabaseRemoteReplayChallengeResponse;
use Quantum\Database\Operation\Engine\ChallengeDatabaseRemoteReplayValidator;
use Quantum\Database\Operation\OperationKind;
use Quantum\Database\Operation\RawOperation;

final class DatabaseRemoteReplayChallengeValidatorTest extends TestCase
{
    public function test_challenge_validator_accepts_matching_verified_response(): void
    {
        $challenger = new class implements DatabaseRemoteReplayChallengerInterface
        {
            public ?DatabaseRemoteReplayChallengeRequest $capturedRequest = null;

            public function challenge(DatabaseRemoteReplayChallengeRequest $request): DatabaseRemoteReplayChallengeResponse
            {
                $this->capturedRequest = $request;

                return DatabaseRemoteReplayChallengeResponse::verified(
                    challenger: 'in_memory_remote_challenger',
                    message: 'Remote node solved the challenge.',
                    challengedNodeId: 'node-a',
                    challengeId: $request->challengeId,
                    challengeNonce: $request->challengeNonce,
                    respondedAt: '2026-08-27T10:00:01+00:00',
                    operationFingerprint: $request->operationFingerprint,
                    confirmationFingerprint: $request->confirmationFingerprint,
                    proofType: 'hmac_sha256',
                    proofFingerprint: 'proof-123',
                    details: ['capability' => 'node-challenge-v1'],
                );
            }
        };

        $validator = new ChallengeDatabaseRemoteReplayValidator(
            challenger: $challenger,
            clock: static fn(): string => '2026-08-27T10:00:00+00:00',
            challengeIdGenerator: static fn(): string => 'challenge-123',
            challengeNonceGenerator: static fn(): string => 'nonce-456',
        );

        $plan = $this->makePlan('mutation-handshake-1');
        $record = new DatabaseIdempotencyRecord(
            keyHash: hash('sha256', 'mutation-handshake-1'),
            operationFingerprint: $plan->fingerprint,
            requestId: 'req-handshake-1',
            connectionName: 'primary',
            logicalTarget: 'users',
            createdAt: '2026-08-27T09:59:50+00:00',
            nodeId: 'node-a',
            status: 'completed',
        );

        $result = $validator->validate($record, $plan, 'node-b', [
            'confirmation_fingerprint' => 'cfp-123',
        ]);

        self::assertSame('verified_remote_validation', $result->status);
        self::assertSame('in_memory_remote_challenger', $result->validator);
        self::assertSame('remote_replay_node_challenge_v1', $result->details['challenge_protocol'] ?? null);
        self::assertSame('challenge-123', $result->details['challenge_id'] ?? null);
        self::assertSame('nonce-456', $result->details['challenge_nonce'] ?? null);
        self::assertSame('node-a', $result->details['challenged_node_id'] ?? null);
        self::assertSame('hmac_sha256', $result->details['proof_type'] ?? null);
        self::assertSame('proof-123', $result->details['proof_fingerprint'] ?? null);
        self::assertSame('verified_contract', $result->details['challenge_validation'] ?? null);
    }

    public function test_challenge_validator_reports_unavailable_response(): void
    {
        $validator = new ChallengeDatabaseRemoteReplayValidator(
            challenger: new class implements DatabaseRemoteReplayChallengerInterface
            {
                public function challenge(DatabaseRemoteReplayChallengeRequest $request): DatabaseRemoteReplayChallengeResponse
                {
                    return DatabaseRemoteReplayChallengeResponse::unavailable(
                        challenger: 'null_remote_replay_challenger',
                        message: 'No challenge transport configured.',
                        details: ['transport' => 'null'],
                    );
                }
            },
            clock: static fn(): string => '2026-08-27T10:05:00+00:00',
            challengeIdGenerator: static fn(): string => 'challenge-234',
            challengeNonceGenerator: static fn(): string => 'nonce-567',
        );

        $plan = $this->makePlan('mutation-handshake-2');
        $record = new DatabaseIdempotencyRecord(
            keyHash: hash('sha256', 'mutation-handshake-2'),
            operationFingerprint: $plan->fingerprint,
            requestId: 'req-handshake-2',
            connectionName: 'primary',
            logicalTarget: 'users',
            createdAt: '2026-08-27T10:04:50+00:00',
            nodeId: 'node-a',
            status: 'completed',
        );

        $result = $validator->validate($record, $plan, 'node-b', [
            'confirmation_fingerprint' => 'cfp-234',
        ]);

        self::assertSame('remote_validation_unavailable', $result->status);
        self::assertSame('null_remote_replay_challenger', $result->validator);
        self::assertSame('challenge-234', $result->details['challenge_id'] ?? null);
        self::assertSame('null', $result->details['transport'] ?? null);
    }

    public function test_challenge_validator_rejects_mismatched_nonce_response(): void
    {
        $validator = new ChallengeDatabaseRemoteReplayValidator(
            challenger: new class implements DatabaseRemoteReplayChallengerInterface
            {
                public function challenge(DatabaseRemoteReplayChallengeRequest $request): DatabaseRemoteReplayChallengeResponse
                {
                    return DatabaseRemoteReplayChallengeResponse::verified(
                        challenger: 'bad_remote_challenger',
                        message: 'Returning a mismatched nonce on purpose.',
                        challengedNodeId: 'node-a',
                        challengeId: $request->challengeId,
                        challengeNonce: 'wrong-nonce',
                        respondedAt: '2026-08-27T10:10:01+00:00',
                        operationFingerprint: $request->operationFingerprint,
                        confirmationFingerprint: $request->confirmationFingerprint,
                        proofType: 'hmac_sha256',
                        proofFingerprint: 'proof-999',
                    );
                }
            },
            clock: static fn(): string => '2026-08-27T10:10:00+00:00',
            challengeIdGenerator: static fn(): string => 'challenge-345',
            challengeNonceGenerator: static fn(): string => 'nonce-678',
        );

        $plan = $this->makePlan('mutation-handshake-3');
        $record = new DatabaseIdempotencyRecord(
            keyHash: hash('sha256', 'mutation-handshake-3'),
            operationFingerprint: $plan->fingerprint,
            requestId: 'req-handshake-3',
            connectionName: 'primary',
            logicalTarget: 'users',
            createdAt: '2026-08-27T10:09:50+00:00',
            nodeId: 'node-a',
            status: 'completed',
        );

        $result = $validator->validate($record, $plan, 'node-b', [
            'confirmation_fingerprint' => 'cfp-345',
        ]);

        self::assertSame('remote_validation_rejected', $result->status);
        self::assertSame('bad_remote_challenger', $result->validator);
        self::assertSame('challenge_nonce_mismatch', $result->details['challenge_validation_failure'] ?? null);
    }

    private function makePlan(string $idempotencyKey): \Quantum\Database\Operation\DatabaseOperationPlan
    {
        $runtime = new DatabaseOperationRuntime(new DatabaseCircuitBreaker(), idempotencyNodeId: 'node-b');
        $context = DatabaseContext::empty()->withConnection(new ChallengeValidatorTestConnection());
        $policy = new DatabaseExecutionPolicy(
            retryLimit: 1,
            retryBackoffMs: 0,
            retryMutationsWhenIdempotent: true,
            remoteReplayValidationMode: 'require',
        );

        return $runtime->plan(
            new RawOperation(
                kind: OperationKind::RawExecute,
                sql: 'UPDATE users SET active = 1 WHERE id = 1',
                params: [],
                comment: 'primary',
                idempotencyKey: $idempotencyKey,
            ),
            $context,
            $policy,
        );
    }
}

final class ChallengeValidatorTestConnection implements ConnectionInterface
{
    public function connect(): void {}

    public function isConnected(): bool
    {
        return true;
    }

    public function close(): void {}

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
        throw new \BadMethodCallException('Not used in this test.');
    }

    public function executeQuery(string $sql, array $params = []): QueryResult
    {
        throw new \BadMethodCallException('Not used in this test.');
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

    public function createSavepoint(string $identifier): void {}

    public function releaseSavepoint(string $identifier): void {}

    public function rollbackToSavepoint(string $identifier): void {}

    public function setTransactionIsolation(TransactionIsolation $level): void {}

    public function getDriverInfo(): DriverInfo
    {
        return new DriverInfo('sqlite', '3.45.1', 'challenge-test');
    }

    public function getCapabilities(): DatabaseCapabilitySet
    {
        return DatabaseCapabilitySet::detectFromDriverInfo('sqlite', '3.45.1');
    }

    public function lastUsedAtSeconds(): float
    {
        return microtime(true);
    }

    public function getNativeHandle(): mixed
    {
        return null;
    }
}
