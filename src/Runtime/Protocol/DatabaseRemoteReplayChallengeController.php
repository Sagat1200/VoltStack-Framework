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
        $requestProtocol = $this->requestProtocol($request, $payload);
        $requestKeyId = $this->requestKeyId($request, $payload);
        $requestCapabilities = $this->requestCapabilities($request, $payload);
        $requestSupportedProtocols = $this->requestSupportedProtocols($payload, $requestProtocol);

        if (! is_array($payload)) {
            return $this->signedResponse(
                DatabaseRemoteReplayChallengeResponse::rejected(
                    challenger: 'remote_replay_challenge_controller',
                    message: 'Remote replay challenge expects a JSON object payload.',
                    details: [
                        'protocol' => $this->signer->protocol(),
                        'request_signature_verification' => 'not_attempted',
                    ],
                ),
                400,
            );
        }

        $signature = trim((string) $request->header(DatabaseRemoteReplayChallengeSigner::SIGNATURE_HEADER, ''));
        if ($signature === '' || ! $this->signer->verifyRequest($payload, $signature, $requestKeyId)) {
            return $this->signedResponse(
                DatabaseRemoteReplayChallengeResponse::rejected(
                    challenger: 'remote_replay_challenge_controller',
                    message: 'Remote replay challenge request signature is invalid.',
                    challengedNodeId: $this->currentNodeId(),
                    challengeId: isset($payload['challenge_id']) ? (string) $payload['challenge_id'] : null,
                    challengeNonce: isset($payload['challenge_nonce']) ? (string) $payload['challenge_nonce'] : null,
                    details: [
                        'protocol' => $this->signer->protocol(),
                        'request_protocol' => $requestProtocol,
                        'request_key_id' => $requestKeyId,
                        'request_signature_verification' => 'failed',
                    ],
                ),
                401,
            );
        }

        $protocolCompatibility = $this->evaluateProtocolCompatibility(
            $requestProtocol,
            $requestSupportedProtocols,
            $requestCapabilities,
        );
        if (($protocolCompatibility['status'] ?? null) !== 'compatible') {
            return $this->signedResponse(
                DatabaseRemoteReplayChallengeResponse::rejected(
                    challenger: 'remote_replay_challenge_controller',
                    message: 'Remote replay challenge protocol is incompatible with this node.',
                    challengedNodeId: $this->currentNodeId(),
                    challengeId: isset($payload['challenge_id']) ? (string) $payload['challenge_id'] : null,
                    challengeNonce: isset($payload['challenge_nonce']) ? (string) $payload['challenge_nonce'] : null,
                    details: array_merge([
                        'request_signature_verification' => 'verified',
                        'request_protocol' => $requestProtocol,
                        'request_key_id' => $requestKeyId,
                    ], $protocolCompatibility),
                ),
                426,
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
                        'protocol' => $this->signer->protocol(),
                        'request_signature_verification' => 'verified',
                        'request_protocol' => $requestProtocol,
                        'request_key_id' => $requestKeyId,
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
                        'protocol' => $this->signer->protocol(),
                        'request_signature_verification' => 'verified',
                        'request_protocol' => $requestProtocol,
                        'request_key_id' => $requestKeyId,
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
                        'protocol' => $this->signer->protocol(),
                        'request_signature_verification' => 'verified',
                        'request_protocol' => $requestProtocol,
                        'request_key_id' => $requestKeyId,
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
                        'protocol' => $this->signer->protocol(),
                        'request_signature_verification' => 'verified',
                        'request_protocol' => $requestProtocol,
                        'request_key_id' => $requestKeyId,
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
                    'protocol' => $this->signer->protocol(),
                    'request_signature_verification' => 'verified',
                    'request_protocol' => $requestProtocol,
                    'request_key_id' => $requestKeyId,
                    'protocol_compatibility' => 'compatible',
                    'protocol_negotiated' => $this->signer->protocol(),
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
        $payload['details'] = array_merge(
            is_array($payload['details'] ?? null) ? $payload['details'] : [],
            [
                'protocol' => $this->signer->protocol(),
                'supported_protocols' => $this->signer->supportedProtocols(),
                'capabilities' => $this->signer->capabilities(),
                'key_id' => $this->signer->activeKeyId(),
            ],
        );
        $signed = $this->json($payload, $statusCode);

        foreach ($this->signer->responseHeaders($payload) as $name => $value) {
            $signed->header($name, $value);
        }

        return $signed;
    }

    /**
     * @param array<string, mixed>|null $payload
     */
    private function requestProtocol(Request $request, ?array $payload): ?string
    {
        $payloadProtocol = is_array($payload) ? ($payload['challenge_protocol'] ?? null) : null;
        $protocol = trim((string) (($payloadProtocol ?? null) ?: $request->header(
            DatabaseRemoteReplayChallengeSigner::PROTOCOL_HEADER,
            '',
        )));

        return $protocol !== '' ? $protocol : null;
    }

    /**
     * @param array<string, mixed>|null $payload
     */
    private function requestKeyId(Request $request, ?array $payload): ?string
    {
        $payloadKeyId = is_array($payload) ? ($payload['key_id'] ?? '') : '';
        $keyId = trim((string) ($request->header(DatabaseRemoteReplayChallengeSigner::KEY_ID_HEADER, '') ?: $payloadKeyId));

        return $keyId !== '' ? $keyId : null;
    }

    /**
     * @param array<string, mixed>|null $payload
     * @return list<string>
     */
    private function requestCapabilities(Request $request, ?array $payload): array
    {
        $payloadCapabilities = is_array($payload) && is_array($payload['capabilities'] ?? null)
            ? $payload['capabilities']
            : [];
        $headerCapabilities = explode(',', (string) $request->header(DatabaseRemoteReplayChallengeSigner::CAPABILITIES_HEADER, ''));

        return $this->normalizeStringList(array_merge($payloadCapabilities, $headerCapabilities));
    }

    /**
     * @param array<string, mixed>|null $payload
     * @return list<string>
     */
    private function requestSupportedProtocols(?array $payload, ?string $requestProtocol): array
    {
        $payloadProtocols = is_array($payload) && is_array($payload['supported_protocols'] ?? null)
            ? $payload['supported_protocols']
            : [];

        return $this->normalizeStringList(array_merge(
            $payloadProtocols,
            $requestProtocol !== null ? [$requestProtocol] : [],
        ));
    }

    /**
     * @param list<string> $requestSupportedProtocols
     * @param list<string> $requestCapabilities
     * @return array<string, mixed>
     */
    private function evaluateProtocolCompatibility(
        ?string $requestProtocol,
        array $requestSupportedProtocols,
        array $requestCapabilities,
    ): array {
        $supportedProtocols = $this->signer->supportedProtocols();
        $commonProtocols = array_values(array_intersect($requestSupportedProtocols, $supportedProtocols));

        if ($requestProtocol !== null && ! $this->signer->supportsProtocol($requestProtocol)) {
            return [
                'protocol' => $this->signer->protocol(),
                'protocol_compatibility' => 'incompatible',
                'protocol_negotiation_reason' => 'requested_protocol_unsupported',
                'requested_protocol' => $requestProtocol,
                'request_supported_protocols' => $requestSupportedProtocols,
                'supported_protocols' => $supportedProtocols,
                'request_capabilities' => $requestCapabilities,
            ];
        }

        if ($requestSupportedProtocols !== [] && $commonProtocols === []) {
            return [
                'protocol' => $this->signer->protocol(),
                'protocol_compatibility' => 'incompatible',
                'protocol_negotiation_reason' => 'no_common_protocol',
                'requested_protocol' => $requestProtocol,
                'request_supported_protocols' => $requestSupportedProtocols,
                'supported_protocols' => $supportedProtocols,
                'request_capabilities' => $requestCapabilities,
            ];
        }

        return [
            'status' => 'compatible',
            'protocol' => $this->signer->protocol(),
            'protocol_compatibility' => 'compatible',
            'protocol_negotiated' => $requestProtocol !== null && $this->signer->supportsProtocol($requestProtocol)
                ? $requestProtocol
                : $this->signer->protocol(),
            'request_supported_protocols' => $requestSupportedProtocols,
            'supported_protocols' => $supportedProtocols,
            'request_capabilities' => $requestCapabilities,
        ];
    }

    /**
     * @param array<mixed> $values
     * @return list<string>
     */
    private function normalizeStringList(array $values): array
    {
        $normalized = [];
        foreach ($values as $value) {
            $candidate = trim((string) $value);
            if ($candidate === '') {
                continue;
            }

            $normalized[] = $candidate;
        }

        return array_values(array_unique($normalized));
    }
}
