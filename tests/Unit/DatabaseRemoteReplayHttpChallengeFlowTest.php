<?php

declare(strict_types=1);

namespace VoltStack\Test\Unit;

use PHPUnit\Framework\TestCase;
use Quantum\Config\ConfigRepository;
use Quantum\Database\Operation\DatabaseIdempotencyRecord;
use Quantum\Database\Operation\DatabaseRemoteReplayChallengeRequest;
use Quantum\Database\Operation\Engine\DatabaseRemoteReplayChallengeSigner;
use Quantum\Database\Operation\Engine\HttpDatabaseRemoteReplayChallenger;
use Quantum\Database\Operation\Engine\InMemoryDatabaseIdempotencyStore;
use Quantum\Http\Request;
use VoltStack\Framework\Application;
use VoltStack\Runtime\Protocol\DatabaseRemoteReplayChallengeController;

final class DatabaseRemoteReplayHttpChallengeFlowTest extends TestCase
{
    private string $basePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->basePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'voltstack-db-remote-replay-http-' . uniqid('', true);
        mkdir($this->basePath, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->deleteDirectory($this->basePath);

        parent::tearDown();
    }

    public function test_http_challenger_completes_signed_end_to_end_handshake(): void
    {
        $app = new Application($this->basePath);
        $app->make(ConfigRepository::class)->set('app.key', 'cluster-secret');
        $app->make(ConfigRepository::class)->set('database.idempotency.node_id', 'node-a');

        $signer = new DatabaseRemoteReplayChallengeSigner($app);
        $store = new InMemoryDatabaseIdempotencyStore();
        $controller = new DatabaseRemoteReplayChallengeController($app, $store, $signer);

        $record = new DatabaseIdempotencyRecord(
            keyHash: hash('sha256', 'mutation-challenge-http'),
            operationFingerprint: 'ofp-123',
            requestId: 'req-123',
            connectionName: 'primary',
            logicalTarget: 'users',
            createdAt: '2026-08-27T11:00:00+00:00',
            nodeId: 'node-a',
            status: 'pending',
        );
        $store->acquire($record);
        $store->complete($record, [
            'confirmation_fingerprint' => 'cfp-123',
            'result_summary' => ['result_type' => 'success_no_rows'],
        ]);

        $challenger = new HttpDatabaseRemoteReplayChallenger(
            signer: $signer,
            endpointMap: ['node-a' => 'http://node-a.internal/_volt/db/remote-replay/challenge'],
            requestTimeoutMs: 1000,
            sender: function (string $endpoint, array $payload, array $headers, int $timeoutMs) use ($controller): array {
                self::assertSame('http://node-a.internal/_volt/db/remote-replay/challenge', $endpoint);
                self::assertSame(1000, $timeoutMs);

                $request = Request::create(
                    '/_volt/db/remote-replay/challenge',
                    'POST',
                    [],
                    [],
                    [],
                    [],
                    [],
                    [
                        'CONTENT_TYPE' => 'application/json',
                        'HTTP_X_VOLTSTACK_REMOTE_REPLAY_SIGNATURE' => $headers[DatabaseRemoteReplayChallengeSigner::SIGNATURE_HEADER] ?? '',
                    ],
                    json_encode($payload, JSON_THROW_ON_ERROR),
                );
                $response = $controller($request);

                $normalizedHeaders = [];
                foreach ($response->headers() as $name => $value) {
                    $normalizedHeaders[strtolower($name)] = $value;
                }

                return [
                    'status' => $response->statusCode(),
                    'headers' => $normalizedHeaders,
                    'body' => $response->content(),
                ];
            },
        );

        $result = $challenger->challenge(new DatabaseRemoteReplayChallengeRequest(
            challengeId: 'challenge-123',
            challengeNonce: 'nonce-456',
            requestedAt: '2026-08-27T11:00:01+00:00',
            currentNodeId: 'node-b',
            sourceNodeId: 'node-a',
            keyHash: hash('sha256', 'mutation-challenge-http'),
            requestId: 'req-123',
            connectionName: 'primary',
            logicalTarget: 'users',
            operationFingerprint: 'ofp-123',
            confirmationFingerprint: 'cfp-123',
            validationMode: 'require',
        ));

        self::assertSame('verified', $result->status);
        self::assertSame('remote_replay_challenge_controller', $result->challenger);
        self::assertSame('node-a', $result->challengedNodeId);
        self::assertSame('challenge_proof_hmac_sha256', $result->proofType);
        self::assertSame('verified', $result->details['response_signature_verification'] ?? null);
    }

    public function test_controller_rejects_invalid_request_signature(): void
    {
        $app = new Application($this->basePath);
        $app->make(ConfigRepository::class)->set('app.key', 'cluster-secret');
        $app->make(ConfigRepository::class)->set('database.idempotency.node_id', 'node-a');

        $signer = new DatabaseRemoteReplayChallengeSigner($app);
        $controller = new DatabaseRemoteReplayChallengeController($app, new InMemoryDatabaseIdempotencyStore(), $signer);
        $payload = [
            'challenge_id' => 'challenge-123',
            'challenge_nonce' => 'nonce-456',
            'source_node_id' => 'node-a',
            'key_hash' => 'key-hash',
            'operation_fingerprint' => 'ofp-123',
            'confirmation_fingerprint' => 'cfp-123',
        ];

        $request = Request::create(
            '/_volt/db/remote-replay/challenge',
            'POST',
            [],
            [],
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_VOLTSTACK_REMOTE_REPLAY_SIGNATURE' => 'invalid-signature',
            ],
            json_encode($payload, JSON_THROW_ON_ERROR),
        );
        $response = $controller($request);
        $body = json_decode($response->content(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(401, $response->statusCode());
        self::assertIsArray($body);
        self::assertSame('rejected', $body['status'] ?? null);
        self::assertTrue($signer->verifyResponse($body, (string) $response->headers()[DatabaseRemoteReplayChallengeSigner::SIGNATURE_HEADER]));
    }

    public function test_http_challenger_rejects_invalid_response_signature(): void
    {
        $app = new Application($this->basePath);
        $app->make(ConfigRepository::class)->set('app.key', 'cluster-secret');

        $signer = new DatabaseRemoteReplayChallengeSigner($app);
        $challenger = new HttpDatabaseRemoteReplayChallenger(
            signer: $signer,
            endpointMap: ['node-a' => 'http://node-a.internal/_volt/db/remote-replay/challenge'],
            sender: static fn(string $endpoint, array $payload, array $headers, int $timeoutMs): array => [
                'status' => 200,
                'headers' => [
                    strtolower(DatabaseRemoteReplayChallengeSigner::SIGNATURE_HEADER) => 'invalid-signature',
                ],
                'body' => json_encode([
                    'status' => 'verified',
                    'challenger' => 'remote_replay_challenge_controller',
                    'challenge_id' => 'challenge-123',
                    'challenge_nonce' => 'nonce-456',
                    'challenged_node_id' => 'node-a',
                    'responded_at' => '2026-08-27T11:10:01+00:00',
                    'operation_fingerprint' => 'ofp-123',
                    'confirmation_fingerprint' => 'cfp-123',
                    'proof_type' => 'challenge_proof_hmac_sha256',
                    'proof_fingerprint' => 'proof-123',
                    'details' => [],
                ], JSON_THROW_ON_ERROR),
            ],
        );

        $result = $challenger->challenge(new DatabaseRemoteReplayChallengeRequest(
            challengeId: 'challenge-123',
            challengeNonce: 'nonce-456',
            requestedAt: '2026-08-27T11:10:00+00:00',
            currentNodeId: 'node-b',
            sourceNodeId: 'node-a',
            keyHash: 'key-hash',
            requestId: 'req-123',
            connectionName: 'primary',
            logicalTarget: 'users',
            operationFingerprint: 'ofp-123',
            confirmationFingerprint: 'cfp-123',
            validationMode: 'require',
        ));

        self::assertSame('rejected', $result->status);
        self::assertSame('failed', $result->details['response_signature_verification'] ?? null);
    }

    private function deleteDirectory(string $path): void
    {
        if (! is_dir($path)) {
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

            unlink($target);
        }

        rmdir($path);
    }
}
