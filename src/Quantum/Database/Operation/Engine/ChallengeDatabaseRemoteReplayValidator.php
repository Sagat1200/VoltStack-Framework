<?php

declare(strict_types=1);

namespace Quantum\Database\Operation\Engine;

use Quantum\Database\Operation\Contracts\DatabaseRemoteReplayChallengerInterface;
use Quantum\Database\Operation\Contracts\DatabaseRemoteReplayValidatorInterface;
use Quantum\Database\Operation\DatabaseIdempotencyRecord;
use Quantum\Database\Operation\DatabaseOperationPlan;
use Quantum\Database\Operation\DatabaseRemoteReplayChallengeRequest;
use Quantum\Database\Operation\DatabaseRemoteReplayChallengeResponse;
use Quantum\Database\Operation\DatabaseRemoteReplayValidationResult;

final class ChallengeDatabaseRemoteReplayValidator implements DatabaseRemoteReplayValidatorInterface
{
    public function __construct(
        private readonly DatabaseRemoteReplayChallengerInterface $challenger,
        private readonly \Closure|null $clock = null,
        private readonly \Closure|null $challengeIdGenerator = null,
        private readonly \Closure|null $challengeNonceGenerator = null,
    ) {}

    public function validate(
        DatabaseIdempotencyRecord $record,
        DatabaseOperationPlan $plan,
        ?string $currentNodeId,
        array $confirmationEvidence,
    ): DatabaseRemoteReplayValidationResult {
        $request = new DatabaseRemoteReplayChallengeRequest(
            challengeId: $this->generateChallengeId(),
            challengeNonce: $this->generateChallengeNonce(),
            requestedAt: $this->timestampNow(),
            currentNodeId: $currentNodeId,
            sourceNodeId: $record->nodeId,
            keyHash: $record->keyHash,
            requestId: $record->requestId,
            connectionName: $record->connectionName,
            logicalTarget: $record->logicalTarget,
            operationFingerprint: $plan->fingerprint,
            confirmationFingerprint: (string) ($confirmationEvidence['confirmation_fingerprint'] ?? ''),
            validationMode: $plan->policy->remoteReplayValidationMode,
            confirmationEvidence: $confirmationEvidence,
        );

        $response = $this->challenger->challenge($request);

        return match ($response->status) {
            'verified' => $this->verifyChallengeResponse($request, $response),
            'rejected' => DatabaseRemoteReplayValidationResult::rejected(
                validator: $this->resolveValidatorName($response),
                message: $response->message ?? 'Remote replay challenge rejected the replay.',
                details: $this->buildChallengeDetails($request, $response),
            ),
            default => DatabaseRemoteReplayValidationResult::unavailable(
                validator: $this->resolveValidatorName($response),
                message: $response->message ?? 'Remote replay challenge is unavailable.',
                details: $this->buildChallengeDetails($request, $response),
            ),
        };
    }

    private function verifyChallengeResponse(
        DatabaseRemoteReplayChallengeRequest $request,
        DatabaseRemoteReplayChallengeResponse $response,
    ): DatabaseRemoteReplayValidationResult {
        $details = $this->buildChallengeDetails($request, $response);

        $mismatch = $this->detectChallengeMismatch($request, $response);
        if ($mismatch !== null) {
            $details['challenge_validation_failure'] = $mismatch;

            return DatabaseRemoteReplayValidationResult::rejected(
                validator: $this->resolveValidatorName($response),
                message: 'Remote replay challenge response did not satisfy the requested challenge contract.',
                details: $details,
            );
        }

        $details['challenge_validation'] = 'verified_contract';

        return DatabaseRemoteReplayValidationResult::verified(
            validator: $this->resolveValidatorName($response),
            message: $response->message ?? 'Remote replay challenge validated the replay.',
            details: $details,
        );
    }

    private function detectChallengeMismatch(
        DatabaseRemoteReplayChallengeRequest $request,
        DatabaseRemoteReplayChallengeResponse $response,
    ): ?string {
        if (($response->challengeId ?? null) !== $request->challengeId) {
            return 'challenge_id_mismatch';
        }

        if (($response->challengeNonce ?? null) !== $request->challengeNonce) {
            return 'challenge_nonce_mismatch';
        }

        if (($response->challengedNodeId ?? null) !== $request->sourceNodeId) {
            return 'challenged_node_mismatch';
        }

        if (($response->operationFingerprint ?? null) !== $request->operationFingerprint) {
            return 'operation_fingerprint_mismatch';
        }

        if (($response->confirmationFingerprint ?? null) !== $request->confirmationFingerprint) {
            return 'confirmation_fingerprint_mismatch';
        }

        if (!is_string($response->proofType) || trim($response->proofType) === '') {
            return 'missing_proof_type';
        }

        if (!is_string($response->proofFingerprint) || trim($response->proofFingerprint) === '') {
            return 'missing_proof_fingerprint';
        }

        if (!is_string($response->respondedAt) || trim($response->respondedAt) === '') {
            return 'missing_responded_at';
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildChallengeDetails(
        DatabaseRemoteReplayChallengeRequest $request,
        DatabaseRemoteReplayChallengeResponse $response,
    ): array {
        return array_merge([
            'challenge_protocol' => 'remote_replay_node_challenge_v1',
            'challenge_id' => $request->challengeId,
            'challenge_nonce' => $request->challengeNonce,
            'challenge_requested_at' => $request->requestedAt,
            'challenged_node_id' => $response->challengedNodeId ?? $request->sourceNodeId,
            'current_node_id' => $request->currentNodeId,
            'operation_fingerprint' => $response->operationFingerprint ?? $request->operationFingerprint,
            'confirmation_fingerprint' => $response->confirmationFingerprint ?? $request->confirmationFingerprint,
            'proof_type' => $response->proofType,
            'proof_fingerprint' => $response->proofFingerprint,
            'responded_at' => $response->respondedAt,
        ], $response->details);
    }

    private function resolveValidatorName(DatabaseRemoteReplayChallengeResponse $response): string
    {
        $challenger = trim($response->challenger);

        return $challenger !== '' ? $challenger : 'challenge_remote_replay_validator';
    }

    private function timestampNow(): string
    {
        if ($this->clock instanceof \Closure) {
            return (string) ($this->clock)();
        }

        return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format(\DATE_ATOM);
    }

    private function generateChallengeId(): string
    {
        if ($this->challengeIdGenerator instanceof \Closure) {
            return (string) ($this->challengeIdGenerator)();
        }

        return 'drv-' . bin2hex(random_bytes(8));
    }

    private function generateChallengeNonce(): string
    {
        if ($this->challengeNonceGenerator instanceof \Closure) {
            return (string) ($this->challengeNonceGenerator)();
        }

        return bin2hex(random_bytes(16));
    }
}
