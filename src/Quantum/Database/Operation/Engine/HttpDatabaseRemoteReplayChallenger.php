<?php

declare(strict_types=1);

namespace Quantum\Database\Operation\Engine;

use Quantum\Database\Operation\Contracts\DatabaseRemoteReplayChallengerInterface;
use Quantum\Database\Operation\DatabaseRemoteReplayChallengeRequest;
use Quantum\Database\Operation\DatabaseRemoteReplayChallengeResponse;

final class HttpDatabaseRemoteReplayChallenger implements DatabaseRemoteReplayChallengerInterface
{
    /**
     * @param null|\Closure(string, array<string, mixed>, array<string, string>, int): array{status:int, headers:array<string, string>, body:string} $sender
     */
    public function __construct(
        private readonly DatabaseRemoteReplayChallengeSigner $signer,
        private readonly DatabaseRemoteReplayChallengeEndpointResolver $endpointResolver,
        private readonly int $requestTimeoutMs = 2000,
        private readonly ?\Closure $sender = null,
    ) {}

    public function challenge(DatabaseRemoteReplayChallengeRequest $request): DatabaseRemoteReplayChallengeResponse
    {
        $sourceNodeId = trim((string) ($request->sourceNodeId ?? ''));
        if ($sourceNodeId === '') {
            return DatabaseRemoteReplayChallengeResponse::unavailable(
                challenger: 'http_remote_replay_challenger',
                message: 'Remote replay challenge requires a source node id.',
            );
        }

        $resolution = $this->endpointResolver->resolve($sourceNodeId);
        $endpoint = trim((string) ($resolution->endpoint ?? ''));
        if ($resolution->status !== 'resolved' || $endpoint === '') {
            $resolutionDetails = $resolution->toArray();
            $nestedResolutionDetails = is_array($resolutionDetails['details'] ?? null)
                ? $resolutionDetails['details']
                : [];

            return DatabaseRemoteReplayChallengeResponse::unavailable(
                challenger: 'http_remote_replay_challenger',
                message: sprintf('No remote replay challenge endpoint is configured for node [%s].', $sourceNodeId),
                details: array_merge($nestedResolutionDetails, [
                    'source_node_id' => $sourceNodeId,
                ], $resolutionDetails),
            );
        }

        $payload = $this->signer->decorateRequestPayload($request->toArray());
        $headers = array_merge([
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ], $this->signer->requestHeaders($payload));

        try {
            $response = $this->dispatch($endpoint, $payload, $headers);
        } catch (\Throwable $exception) {
            return DatabaseRemoteReplayChallengeResponse::unavailable(
                challenger: 'http_remote_replay_challenger',
                message: 'Remote replay challenge transport failed.',
                details: [
                    'source_node_id' => $sourceNodeId,
                    'endpoint' => $endpoint,
                    'endpoint_strategy' => $resolution->strategy,
                    'transport_error' => $exception->getMessage(),
                ],
            );
        }

        $body = trim($response['body']);
        if ($body === '') {
            return DatabaseRemoteReplayChallengeResponse::unavailable(
                challenger: 'http_remote_replay_challenger',
                message: 'Remote replay challenge returned an empty response body.',
                details: [
                    'source_node_id' => $sourceNodeId,
                    'endpoint' => $endpoint,
                    'endpoint_strategy' => $resolution->strategy,
                    'http_status' => $response['status'],
                ],
            );
        }

        try {
            $payload = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable $exception) {
            return DatabaseRemoteReplayChallengeResponse::unavailable(
                challenger: 'http_remote_replay_challenger',
                message: 'Remote replay challenge returned invalid JSON.',
                details: [
                    'source_node_id' => $sourceNodeId,
                    'endpoint' => $endpoint,
                    'endpoint_strategy' => $resolution->strategy,
                    'http_status' => $response['status'],
                    'transport_error' => $exception->getMessage(),
                ],
            );
        }

        if (! is_array($payload)) {
            return DatabaseRemoteReplayChallengeResponse::unavailable(
                challenger: 'http_remote_replay_challenger',
                message: 'Remote replay challenge returned an invalid payload.',
                details: [
                    'source_node_id' => $sourceNodeId,
                    'endpoint' => $endpoint,
                    'endpoint_strategy' => $resolution->strategy,
                    'http_status' => $response['status'],
                ],
            );
        }

        $responseKeyId = trim((string) ($response['headers'][strtolower(DatabaseRemoteReplayChallengeSigner::KEY_ID_HEADER)] ?? ($payload['details']['key_id'] ?? '')));
        $signature = trim((string) ($response['headers'][strtolower(DatabaseRemoteReplayChallengeSigner::SIGNATURE_HEADER)] ?? ''));
        if ($signature === '' || ! $this->signer->verifyResponse($payload, $signature, $responseKeyId !== '' ? $responseKeyId : null)) {
            return DatabaseRemoteReplayChallengeResponse::rejected(
                challenger: 'http_remote_replay_challenger',
                message: 'Remote replay challenge response signature could not be verified.',
                challengedNodeId: $sourceNodeId,
                challengeId: (string) ($payload['challenge_id'] ?? $request->challengeId),
                challengeNonce: (string) ($payload['challenge_nonce'] ?? $request->challengeNonce),
                respondedAt: isset($payload['responded_at']) ? (string) $payload['responded_at'] : null,
                operationFingerprint: isset($payload['operation_fingerprint']) ? (string) $payload['operation_fingerprint'] : null,
                confirmationFingerprint: isset($payload['confirmation_fingerprint']) ? (string) $payload['confirmation_fingerprint'] : null,
                proofType: isset($payload['proof_type']) ? (string) $payload['proof_type'] : null,
                proofFingerprint: isset($payload['proof_fingerprint']) ? (string) $payload['proof_fingerprint'] : null,
                details: [
                    'source_node_id' => $sourceNodeId,
                    'endpoint' => $endpoint,
                    'endpoint_strategy' => $resolution->strategy,
                    'http_status' => $response['status'],
                    'response_key_id' => $responseKeyId !== '' ? $responseKeyId : null,
                    'response_signature_verification' => 'failed',
                ],
            );
        }

        $challengeResponse = DatabaseRemoteReplayChallengeResponse::fromArray($payload);
        $responseProtocol = trim((string) ($response['headers'][strtolower(DatabaseRemoteReplayChallengeSigner::PROTOCOL_HEADER)] ?? ($challengeResponse->details['protocol'] ?? '')));
        $responseCapabilities = $this->normalizeCapabilities(
            (string) ($response['headers'][strtolower(DatabaseRemoteReplayChallengeSigner::CAPABILITIES_HEADER)] ?? ''),
            $challengeResponse->details['capabilities'] ?? [],
        );

        if ($responseProtocol !== '' && ! $this->signer->supportsProtocol($responseProtocol)) {
            return DatabaseRemoteReplayChallengeResponse::rejected(
                challenger: $challengeResponse->challenger !== 'unknown'
                    ? $challengeResponse->challenger
                    : 'http_remote_replay_challenger',
                message: 'Remote replay challenge responder uses an incompatible protocol.',
                challengedNodeId: $challengeResponse->challengedNodeId ?? $sourceNodeId,
                challengeId: $challengeResponse->challengeId ?? $request->challengeId,
                challengeNonce: $challengeResponse->challengeNonce ?? $request->challengeNonce,
                respondedAt: $challengeResponse->respondedAt,
                operationFingerprint: $challengeResponse->operationFingerprint,
                confirmationFingerprint: $challengeResponse->confirmationFingerprint,
                proofType: $challengeResponse->proofType,
                proofFingerprint: $challengeResponse->proofFingerprint,
                details: array_merge($challengeResponse->details, [
                    'source_node_id' => $sourceNodeId,
                    'endpoint' => $endpoint,
                    'endpoint_strategy' => $resolution->strategy,
                    'http_status' => $response['status'],
                    'response_protocol' => $responseProtocol,
                    'response_capabilities' => $responseCapabilities,
                    'response_key_id' => $responseKeyId !== '' ? $responseKeyId : null,
                    'protocol_compatibility' => 'incompatible',
                    'protocol_negotiation_reason' => 'response_protocol_unsupported',
                    'response_signature_verification' => 'verified',
                ]),
            );
        }

        return new DatabaseRemoteReplayChallengeResponse(
            status: $challengeResponse->status,
            challenger: $challengeResponse->challenger !== 'unknown'
                ? $challengeResponse->challenger
                : 'http_remote_replay_challenger',
            message: $challengeResponse->message,
            challengedNodeId: $challengeResponse->challengedNodeId,
            challengeId: $challengeResponse->challengeId,
            challengeNonce: $challengeResponse->challengeNonce,
            respondedAt: $challengeResponse->respondedAt,
            operationFingerprint: $challengeResponse->operationFingerprint,
            confirmationFingerprint: $challengeResponse->confirmationFingerprint,
            proofType: $challengeResponse->proofType,
            proofFingerprint: $challengeResponse->proofFingerprint,
            details: array_merge($challengeResponse->details, [
                'source_node_id' => $sourceNodeId,
                'endpoint' => $endpoint,
                'endpoint_strategy' => $resolution->strategy,
                'http_status' => $response['status'],
                'response_protocol' => $responseProtocol !== '' ? $responseProtocol : null,
                'response_capabilities' => $responseCapabilities,
                'response_key_id' => $responseKeyId !== '' ? $responseKeyId : null,
                'response_signature_verification' => 'verified',
            ]),
        );
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, string> $headers
     * @return array{status:int, headers:array<string, string>, body:string}
     */
    private function dispatch(string $endpoint, array $payload, array $headers): array
    {
        if ($this->sender instanceof \Closure) {
            return ($this->sender)($endpoint, $payload, $headers, $this->requestTimeoutMs);
        }

        $body = json_encode($payload, JSON_THROW_ON_ERROR);
        $headerLines = [];
        foreach ($headers as $name => $value) {
            $headerLines[] = $name . ': ' . $value;
        }

        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => implode("\r\n", $headerLines),
                'content' => $body,
                'timeout' => max(1, (int) ceil($this->requestTimeoutMs / 1000)),
                'ignore_errors' => true,
            ],
        ]);

        $rawBody = file_get_contents($endpoint, false, $context);
        if ($rawBody === false) {
            throw new \RuntimeException('Unable to open remote replay challenge endpoint.');
        }

        $status = 0;
        $normalizedHeaders = [];
        foreach ($http_response_header ?? [] as $index => $headerLine) {
            if ($index === 0) {
                if (preg_match('/\s(\d{3})\s/', $headerLine, $matches) === 1) {
                    $status = (int) $matches[1];
                }

                continue;
            }

            $separator = strpos($headerLine, ':');
            if ($separator === false) {
                continue;
            }

            $name = strtolower(trim(substr($headerLine, 0, $separator)));
            $value = trim(substr($headerLine, $separator + 1));
            $normalizedHeaders[$name] = $value;
        }

        return [
            'status' => $status,
            'headers' => $normalizedHeaders,
            'body' => $rawBody,
        ];
    }

    /**
     * @param mixed $payloadCapabilities
     * @return list<string>
     */
    private function normalizeCapabilities(string $headerCapabilities, mixed $payloadCapabilities): array
    {
        $values = array_filter(array_map('trim', explode(',', $headerCapabilities)));
        if (is_array($payloadCapabilities)) {
            foreach ($payloadCapabilities as $capability) {
                $candidate = trim((string) $capability);
                if ($candidate === '') {
                    continue;
                }

                $values[] = $candidate;
            }
        }

        return array_values(array_unique($values));
    }
}
