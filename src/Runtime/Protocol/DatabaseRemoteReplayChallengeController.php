<?php

declare(strict_types=1);

namespace VoltStack\Runtime\Protocol;

use Quantum\Controllers\Controller;
use Quantum\Database\Operation\Contracts\DatabaseIdempotencyStoreInterface;
use Quantum\Database\Operation\DatabaseRemoteReplayChallengeResponse;
use Quantum\Database\Operation\Engine\DatabaseRemoteReplayChallengeSigner;
use Quantum\Http\JsonResponse;
use Quantum\Http\Request;
use VoltStack\Framework\Application;

final class DatabaseRemoteReplayChallengeController extends Controller
{
    public function __construct(
        private readonly Application $app,
        private readonly DatabaseIdempotencyStoreInterface $store,
        private readonly DatabaseRemoteReplayChallengeSigner $signer,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $payload = $this->decodePayload($request);
        if (! is_array($payload)) {
            return $this->signedResponse(
                DatabaseRemoteReplayChallengeResponse::rejected(
                    challenger: 'remote_replay_challenge_controller',
                    message: 'Remote replay challenge expects a JSON object payload.',
                    details: [
                        'protocol' => DatabaseRemoteReplayChallengeSigner::PROTOCOL,
                        'request_signature_verification' => 'not_attempted',
                    ],
                ),
                400,
            );
        }

        $signature = trim((string) $request->header(DatabaseRemoteReplayChallengeSigner::SIGNATURE_HEADER, ''));
        if ($signature === '' || ! $this->signer->verifyRequest($payload, $signature)) {
            return $this->signedResponse(
                DatabaseRemoteReplayChallengeResponse::rejected(
                    challenger: 'remote_replay_challenge_controller',
                    message: 'Remote replay challenge request signature is invalid.',
                    challengedNodeId: $this->currentNodeId(),
                    challengeId: isset($payload['challenge_id']) ? (string) $payload['challenge_id'] : null,
                    challengeNonce: isset($payload['challenge_nonce']) ? (string) $payload['challenge_nonce'] : null,
                    details: [
                        'protocol' => DatabaseRemoteReplayChallengeSigner::PROTOCOL,
                        'request_signature_verification' => 'failed',
                    ],
                ),
                401,
            );
        }

        $keyHash = trim((string) ($payload['key_hash'] ?? ''));
        $challengeId = trim((string) ($payload['challenge_id'] ?? ''));
        $challengeNonce = trim((string) ($payload['challenge_nonce'] ?? ''));
        $operationFingerprint = trim((string) ($payload['operation_fingerprint'] ?? ''));
        $confirmationFingerprint = trim((string) ($payload['confirmation_fingerprint'] ?? ''));
        $sourceNodeId = trim((string) ($payload['source_node_id'] ?? ''));

        if (
            $keyHash === ''
            || $challengeId === ''
            || $challengeNonce === ''
            || $operationFingerprint === ''
            || $confirmationFingerprint === ''
            || $sourceNodeId === ''
        ) {
            return $this->signedResponse(
                DatabaseRemoteReplayChallengeResponse::rejected(
                    challenger: 'remote_replay_challenge_controller',
                    message: 'Remote replay challenge payload is incomplete.',
                    challengedNodeId: $this->currentNodeId(),
                    challengeId: $challengeId !== '' ? $challengeId : null,
                    challengeNonce: $challengeNonce !== '' ? $challengeNonce : null,
                    details: [
                        'protocol' => DatabaseRemoteReplayChallengeSigner::PROTOCOL,
                        'request_signature_verification' => 'verified',
                    ],
                ),
                422,
            );
        }

        $record = $this->store->find($keyHash);
        if ($record === null) {
            return $this->signedResponse(
                DatabaseRemoteReplayChallengeResponse::rejected(
                    challenger: 'remote_replay_challenge_controller',
                    message: 'Remote replay challenge could not find the requested idempotency record.',
                    challengedNodeId: $this->currentNodeId(),
                    challengeId: $challengeId,
                    challengeNonce: $challengeNonce,
                    details: [
                        'protocol' => DatabaseRemoteReplayChallengeSigner::PROTOCOL,
                        'request_signature_verification' => 'verified',
                        'key_hash' => $keyHash,
                    ],
                ),
                404,
            );
        }

        if ($record->status !== 'completed') {
            return $this->signedResponse(
                DatabaseRemoteReplayChallengeResponse::rejected(
                    challenger: 'remote_replay_challenge_controller',
                    message: 'Remote replay challenge only accepts completed idempotency confirmations.',
                    challengedNodeId: $this->currentNodeId(),
                    challengeId: $challengeId,
                    challengeNonce: $challengeNonce,
                    operationFingerprint: $record->operationFingerprint,
                    details: [
                        'protocol' => DatabaseRemoteReplayChallengeSigner::PROTOCOL,
                        'request_signature_verification' => 'verified',
                        'record_status' => $record->status,
                    ],
                ),
                409,
            );
        }

        $storedConfirmationFingerprint = trim((string) ($record->confirmation['confirmation_fingerprint'] ?? ''));
        if (
            $record->nodeId !== $sourceNodeId
            || $record->operationFingerprint !== $operationFingerprint
            || $storedConfirmationFingerprint === ''
            || $storedConfirmationFingerprint !== $confirmationFingerprint
        ) {
            return $this->signedResponse(
                DatabaseRemoteReplayChallengeResponse::rejected(
                    challenger: 'remote_replay_challenge_controller',
                    message: 'Remote replay challenge detected a fingerprint mismatch.',
                    challengedNodeId: $this->currentNodeId(),
                    challengeId: $challengeId,
                    challengeNonce: $challengeNonce,
                    operationFingerprint: $record->operationFingerprint,
                    confirmationFingerprint: $storedConfirmationFingerprint !== '' ? $storedConfirmationFingerprint : null,
                    details: [
                        'protocol' => DatabaseRemoteReplayChallengeSigner::PROTOCOL,
                        'request_signature_verification' => 'verified',
                        'source_node_match' => $record->nodeId === $sourceNodeId ? 'matched' : 'mismatched',
                    ],
                ),
                409,
            );
        }

        $respondedAt = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format(\DATE_ATOM);
        $proofFingerprint = $this->signer->signProof(
            challengeId: $challengeId,
            challengeNonce: $challengeNonce,
            confirmationFingerprint: $confirmationFingerprint,
            keyHash: $keyHash,
            nodeId: $record->nodeId,
        );

        return $this->signedResponse(
            DatabaseRemoteReplayChallengeResponse::verified(
                challenger: 'remote_replay_challenge_controller',
                message: 'Remote replay challenge verified the persisted confirmation.',
                challengedNodeId: $this->currentNodeId(),
                challengeId: $challengeId,
                challengeNonce: $challengeNonce,
                respondedAt: $respondedAt,
                operationFingerprint: $record->operationFingerprint,
                confirmationFingerprint: $storedConfirmationFingerprint,
                proofType: 'challenge_proof_hmac_sha256',
                proofFingerprint: $proofFingerprint,
                details: [
                    'protocol' => DatabaseRemoteReplayChallengeSigner::PROTOCOL,
                    'request_signature_verification' => 'verified',
                    'requester_node_id' => isset($payload['current_node_id']) ? (string) $payload['current_node_id'] : null,
                    'source_node_id' => $record->nodeId,
                    'key_hash' => $record->keyHash,
                ],
            ),
            200,
        );
    }

    private function currentNodeId(): ?string
    {
        $nodeId = trim((string) $this->app->config(
            'database.idempotency.node_id',
            (string) $this->app->config('database.health.node_id', (string) $this->app->config('app.name', 'app')),
        ));

        return $nodeId !== '' ? $nodeId : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function decodePayload(Request $request): ?array
    {
        $content = trim((string) ($request->content() ?? ''));
        if ($content === '') {
            return null;
        }

        try {
            $payload = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return null;
        }

        return is_array($payload) ? $payload : null;
    }

    private function signedResponse(DatabaseRemoteReplayChallengeResponse $response, int $statusCode): JsonResponse
    {
        $payload = $response->toArray();
        $signed = $this->json($payload, $statusCode);
        $signed->header(DatabaseRemoteReplayChallengeSigner::PROTOCOL_HEADER, DatabaseRemoteReplayChallengeSigner::PROTOCOL);
        $signed->header(DatabaseRemoteReplayChallengeSigner::SIGNATURE_HEADER, $this->signer->signResponse($payload));

        return $signed;
    }
}
