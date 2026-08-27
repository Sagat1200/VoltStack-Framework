<?php

declare(strict_types=1);

namespace Quantum\Database\Operation\Engine;

use VoltStack\Framework\Application;

final class DatabaseRemoteReplayChallengeSigner
{
    public const PROTOCOL = 'remote_replay_node_challenge_v1';
    public const SIGNATURE_HEADER = 'X-VoltStack-Remote-Replay-Signature';
    public const PROTOCOL_HEADER = 'X-VoltStack-Remote-Replay-Protocol';

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
    public function verifyRequest(array $payload, string $signature): bool
    {
        return hash_equals($this->signRequest($payload), $signature);
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
    public function verifyResponse(array $payload, string $signature): bool
    {
        return hash_equals($this->signResponse($payload), $signature);
    }

    public function signProof(
        string $challengeId,
        string $challengeNonce,
        string $confirmationFingerprint,
        string $keyHash,
        ?string $nodeId,
    ): string {
        $material = implode('|', [
            self::PROTOCOL,
            $challengeId,
            $challengeNonce,
            $confirmationFingerprint,
            $keyHash,
            (string) ($nodeId ?? ''),
        ]);

        return hash_hmac('sha256', $material, $this->secret());
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function signPayload(string $component, array $payload): string
    {
        $normalized = $this->normalizeValue($payload);
        $encoded = json_encode([
            'component' => $component,
            'payload' => $normalized,
        ], JSON_THROW_ON_ERROR);

        return hash_hmac('sha256', $encoded, $this->secret());
    }

    private function secret(): string
    {
        $secret = trim((string) $this->app->config('database.idempotency.remote_replay_challenge.shared_secret', ''));
        if ($secret !== '') {
            return $secret;
        }

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
     * @param array<mixed> $value
     */
    private function isList(array $value): bool
    {
        return array_keys($value) === range(0, count($value) - 1);
    }
}
