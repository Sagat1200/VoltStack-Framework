<?php

declare(strict_types=1);

namespace Quantum\Database\Operation\Engine;

use VoltStack\Framework\Application;

final class DatabaseRemoteReplayChallengeSigner
{
    public const PROTOCOL = 'remote_replay_node_challenge_v1';
    public const SIGNATURE_HEADER = 'X-VoltStack-Remote-Replay-Signature';
    public const PROTOCOL_HEADER = 'X-VoltStack-Remote-Replay-Protocol';
    public const KEY_ID_HEADER = 'X-VoltStack-Remote-Replay-Key-Id';
    public const CAPABILITIES_HEADER = 'X-VoltStack-Remote-Replay-Capabilities';

    public function __construct(private readonly Application $app) {}

    /**
     * @param array<string, mixed> $payload
     */
    public function signRequest(array $payload): string
    {
        return $this->signPayload('db.remote_replay.challenge.request.v1', $payload);
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function verifyRequest(array $payload, string $signature, ?string $keyId = null): bool
    {
        return $this->verifyPayload('db.remote_replay.challenge.request.v1', $payload, $signature, $keyId);
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function signResponse(array $payload): string
    {
        return $this->signPayload('db.remote_replay.challenge.response.v1', $payload);
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function verifyResponse(array $payload, string $signature, ?string $keyId = null): bool
    {
        return $this->verifyPayload('db.remote_replay.challenge.response.v1', $payload, $signature, $keyId);
    }

    public function protocol(): string
    {
        $protocol = trim((string) $this->app->config('database.idempotency.remote_replay_challenge.protocol', self::PROTOCOL));

        return $protocol !== '' ? $protocol : self::PROTOCOL;
    }

    /**
     * @return list<string>
     */
    public function supportedProtocols(): array
    {
        $configured = $this->normalizeStringList(
            $this->app->config('database.idempotency.remote_replay_challenge.supported_protocols', []),
        );

        $protocols = array_values(array_unique([
            $this->protocol(),
            ...$configured,
        ]));

        return $protocols === [] ? [self::PROTOCOL] : $protocols;
    }

    public function supportsProtocol(?string $protocol): bool
    {
        $normalized = trim((string) ($protocol ?? ''));
        if ($normalized === '') {
            return false;
        }

        return in_array($normalized, $this->supportedProtocols(), true);
    }

    /**
     * @return list<string>
     */
    public function capabilities(): array
    {
        $configured = $this->normalizeStringList(
            $this->app->config('database.idempotency.remote_replay_challenge.capabilities', []),
        );

        $capabilities = array_values(array_unique([
            'signed_request_hmac_sha256',
            'signed_response_hmac_sha256',
            'challenge_proof_hmac_sha256',
            'key_rotation',
            ...$configured,
        ]));

        return $capabilities;
    }

    public function activeKeyId(): string
    {
        $configured = trim((string) $this->app->config('database.idempotency.remote_replay_challenge.key_id', ''));
        $ring = $this->keyRing();

        if ($configured !== '' && isset($ring[$configured])) {
            return $configured;
        }

        return (string) array_key_first($ring);
    }

    /**
     * @return array<string, string>
     */
    public function requestHeaders(array $payload): array
    {
        return [
            self::PROTOCOL_HEADER => $this->protocol(),
            self::KEY_ID_HEADER => $this->activeKeyId(),
            self::CAPABILITIES_HEADER => implode(',', $this->capabilities()),
            self::SIGNATURE_HEADER => $this->signRequest($payload),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function responseHeaders(array $payload): array
    {
        return [
            self::PROTOCOL_HEADER => $this->protocol(),
            self::KEY_ID_HEADER => $this->activeKeyId(),
            self::CAPABILITIES_HEADER => implode(',', $this->capabilities()),
            self::SIGNATURE_HEADER => $this->signResponse($payload),
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function decorateRequestPayload(array $payload): array
    {
        $payload['challenge_protocol'] = $this->protocol();
        $payload['supported_protocols'] = $this->supportedProtocols();
        $payload['capabilities'] = $this->capabilities();
        $payload['key_id'] = $this->activeKeyId();

        return $payload;
    }

    public function signProof(
        string $challengeId,
        string $challengeNonce,
        string $confirmationFingerprint,
        string $keyHash,
        ?string $nodeId,
        ?string $keyId = null,
    ): string {
        $material = implode('|', [
            $this->protocol(),
            $challengeId,
            $challengeNonce,
            $confirmationFingerprint,
            $keyHash,
            (string) ($nodeId ?? ''),
        ]);

        return hash_hmac('sha256', $material, $this->secret($keyId));
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function signPayload(string $component, array $payload, ?string $keyId = null): string
    {
        $normalized = $this->normalizeValue($payload);
        $encoded = json_encode([
            'component' => $component,
            'payload' => $normalized,
        ], JSON_THROW_ON_ERROR);

        return hash_hmac('sha256', $encoded, $this->secret($keyId));
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function verifyPayload(string $component, array $payload, string $signature, ?string $keyId): bool
    {
        $candidateKeyId = trim((string) ($keyId ?? ''));
        if ($candidateKeyId !== '') {
            $ring = $this->keyRing();
            if (isset($ring[$candidateKeyId])) {
                return hash_equals($this->signPayload($component, $payload, $candidateKeyId), $signature);
            }
        }

        foreach ($this->keyRing() as $resolvedKeyId => $_secret) {
            if (hash_equals($this->signPayload($component, $payload, $resolvedKeyId), $signature)) {
                return true;
            }
        }

        return false;
    }

    private function secret(?string $keyId = null): string
    {
        $ring = $this->keyRing();
        $candidateKeyId = trim((string) ($keyId ?? ''));

        if ($candidateKeyId !== '' && isset($ring[$candidateKeyId])) {
            return $ring[$candidateKeyId];
        }

        $activeKeyId = $this->activeKeyId();
        $activeSecret = $ring[$activeKeyId] ?? null;
        if (is_string($activeSecret) && $activeSecret !== '') {
            return $activeSecret;
        }

        $firstSecret = reset($ring);
        if (is_string($firstSecret) && $firstSecret !== '') {
            return $firstSecret;
        }

        return $this->fallbackSecret();
    }

    /**
     * @return array<string, string>
     */
    private function keyRing(): array
    {
        $configuredKeyId = trim((string) $this->app->config('database.idempotency.remote_replay_challenge.key_id', ''));
        $configuredSecret = trim((string) $this->app->config('database.idempotency.remote_replay_challenge.shared_secret', ''));
        $configuredMap = $this->app->config('database.idempotency.remote_replay_challenge.shared_secret_map', []);

        $ring = [];
        if (is_array($configuredMap)) {
            foreach ($configuredMap as $keyId => $secret) {
                $normalizedKeyId = trim((string) $keyId);
                $normalizedSecret = trim((string) $secret);
                if ($normalizedKeyId === '' || $normalizedSecret === '') {
                    continue;
                }

                $ring[$normalizedKeyId] = $normalizedSecret;
            }
        }

        if ($configuredSecret !== '') {
            $fallbackKeyId = $configuredKeyId !== '' ? $configuredKeyId : 'legacy';
            $ring[$fallbackKeyId] = $ring[$fallbackKeyId] ?? $configuredSecret;
        }

        if ($ring === []) {
            $fallbackKeyId = $configuredKeyId !== '' ? $configuredKeyId : 'app-default';
            $ring[$fallbackKeyId] = $this->fallbackSecret();
        }

        return $ring;
    }

    private function fallbackSecret(): string
    {
        $appKey = trim((string) $this->app->config('app.key', ''));
        if ($appKey !== '') {
            return $appKey;
        }

        return 'voltstack|' . $this->app->basePath();
    }

    private function normalizeValue(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if ($this->isList($value)) {
            return array_map(fn(mixed $item): mixed => $this->normalizeValue($item), $value);
        }

        ksort($value);

        foreach ($value as $key => $item) {
            $value[$key] = $this->normalizeValue($item);
        }

        return $value;
    }

    /**
     * @return list<string>
     */
    private function normalizeStringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $normalized = [];
        foreach ($value as $item) {
            $candidate = trim((string) $item);
            if ($candidate === '') {
                continue;
            }

            $normalized[] = $candidate;
        }

        return array_values(array_unique($normalized));
    }

    /**
     * @param array<mixed> $value
     */
    private function isList(array $value): bool
    {
        return array_keys($value) === range(0, count($value) - 1);
    }
}
