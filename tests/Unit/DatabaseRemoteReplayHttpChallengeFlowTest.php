<?php

declare(strict_types=1);

namespace VoltStack\Test\Unit;

use PHPUnit\Framework\TestCase;
use Quantum\Config\ConfigRepository;
use Quantum\Database\Operation\DatabaseIdempotencyRecord;
use Quantum\Database\Operation\DatabaseTelemetryReport;
use Quantum\Database\Operation\Engine\DirectoryDatabaseHealthStore;
use Quantum\Database\Operation\DatabaseRemoteReplayChallengeRequest;
use Quantum\Database\Operation\Engine\DatabaseRemoteReplayChallengeEndpointResolver;
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
        $requesterApp = new Application($this->basePath . DIRECTORY_SEPARATOR . 'requester');
        $requesterApp->make(ConfigRepository::class)->set('app.key', 'cluster-secret');
        $requesterApp->make(ConfigRepository::class)->set('database.idempotency.node_id', 'node-b');

        $responderApp = new Application($this->basePath . DIRECTORY_SEPARATOR . 'responder');
        $responderApp->make(ConfigRepository::class)->set('app.key', 'cluster-secret');
        $responderApp->make(ConfigRepository::class)->set('database.idempotency.node_id', 'node-a');

        $signer = new DatabaseRemoteReplayChallengeSigner($requesterApp);
        $store = new InMemoryDatabaseIdempotencyStore();
        $controller = new DatabaseRemoteReplayChallengeController(
            $responderApp,
            $store,
            new DatabaseRemoteReplayChallengeSigner($responderApp),
        );

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
            endpointResolver: new DatabaseRemoteReplayChallengeEndpointResolver(
                endpointMap: ['node-a' => 'http://node-a.internal/_volt/db/remote-replay/challenge'],
            ),
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
                        'HTTP_X_VOLTSTACK_REMOTE_REPLAY_PROTOCOL' => $headers[DatabaseRemoteReplayChallengeSigner::PROTOCOL_HEADER] ?? '',
                        'HTTP_X_VOLTSTACK_REMOTE_REPLAY_KEY_ID' => $headers[DatabaseRemoteReplayChallengeSigner::KEY_ID_HEADER] ?? '',
                        'HTTP_X_VOLTSTACK_REMOTE_REPLAY_CAPABILITIES' => $headers[DatabaseRemoteReplayChallengeSigner::CAPABILITIES_HEADER] ?? '',
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
        self::assertSame('remote_replay_node_challenge_v1', $result->details['protocol_negotiated'] ?? null);
        self::assertSame('app-default', $result->details['response_key_id'] ?? null);
        self::assertSame('verified', $result->details['response_signature_verification'] ?? null);
    }

    public function test_http_challenger_discovers_remote_endpoint_from_health_advertisement(): void
    {
        $sharedHealthDirectory = $this->basePath . DIRECTORY_SEPARATOR . 'shared-health';
        mkdir($sharedHealthDirectory, 0777, true);

        $requesterApp = new Application($this->basePath . DIRECTORY_SEPARATOR . 'requester-health');
        $requesterApp->make(ConfigRepository::class)->set('app.key', 'cluster-secret');
        $requesterApp->make(ConfigRepository::class)->set('database.idempotency.node_id', 'node-b');

        $responderApp = new Application($this->basePath . DIRECTORY_SEPARATOR . 'responder-health');
        $responderConfig = $responderApp->make(ConfigRepository::class);
        $responderConfig->set('app.key', 'cluster-secret');
        $responderConfig->set('app.url', 'http://node-a.internal');
        $responderConfig->set('database.idempotency.node_id', 'node-a');
        $responderConfig->set('database.idempotency.remote_replay_challenge.path', '/_volt/db/remote-replay/challenge');

        $healthStore = new DirectoryDatabaseHealthStore($sharedHealthDirectory);
        $healthStore->persist(new DatabaseTelemetryReport(
            requestId: 'req-health-adv',
            tenantId: null,
            traceId: null,
            generatedAt: '2026-08-29T20:10:00+00:00',
            summary: [
                'remote_replay_challenge' => [
                    'cluster_advertisement' => [
                        'node_id' => 'node-a',
                        'endpoint' => 'http://node-a.internal/_volt/db/remote-replay/challenge',
                        'source' => 'app_url',
                        'protocol' => 'remote_replay_node_challenge_v1',
                        'supported_protocols' => ['remote_replay_node_challenge_v1'],
                        'capabilities' => [],
                        'key_id' => 'app-default',
                    ],
                ],
            ],
            health: [],
            nodeId: 'node-a',
        ));

        $signer = new DatabaseRemoteReplayChallengeSigner($requesterApp);
        $store = new InMemoryDatabaseIdempotencyStore();
        $controller = new DatabaseRemoteReplayChallengeController(
            $responderApp,
            $store,
            new DatabaseRemoteReplayChallengeSigner($responderApp),
        );

        $record = new DatabaseIdempotencyRecord(
            keyHash: hash('sha256', 'mutation-challenge-health'),
            operationFingerprint: 'ofp-health',
            requestId: 'req-health',
            connectionName: 'primary',
            logicalTarget: 'users',
            createdAt: '2026-08-29T20:10:01+00:00',
            nodeId: 'node-a',
            status: 'pending',
        );
        $store->acquire($record);
        $store->complete($record, [
            'confirmation_fingerprint' => 'cfp-health',
            'result_summary' => ['result_type' => 'success_no_rows'],
        ]);

        $challenger = new HttpDatabaseRemoteReplayChallenger(
            signer: $signer,
            endpointResolver: new DatabaseRemoteReplayChallengeEndpointResolver(
                endpointMap: [],
                advertisedEndpointProvider: static function () use ($healthStore): array {
                    $advertised = [];
                    foreach ($healthStore->recent(10) as $report) {
                        $summary = is_array($report->summary) ? $report->summary : [];
                        $remoteReplayChallenge = is_array($summary['remote_replay_challenge'] ?? null)
                            ? $summary['remote_replay_challenge']
                            : [];
                        $advertisement = is_array($remoteReplayChallenge['cluster_advertisement'] ?? null)
                            ? $remoteReplayChallenge['cluster_advertisement']
                            : null;
                        $nodeId = trim((string) (($advertisement['node_id'] ?? null) ?: $report->nodeId ?: ''));

                        if ($nodeId === '' || !is_array($advertisement)) {
                            continue;
                        }

                        $advertised[$nodeId] = $advertisement;
                    }

                    return $advertised;
                },
            ),
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
                        'HTTP_X_VOLTSTACK_REMOTE_REPLAY_PROTOCOL' => $headers[DatabaseRemoteReplayChallengeSigner::PROTOCOL_HEADER] ?? '',
                        'HTTP_X_VOLTSTACK_REMOTE_REPLAY_KEY_ID' => $headers[DatabaseRemoteReplayChallengeSigner::KEY_ID_HEADER] ?? '',
                        'HTTP_X_VOLTSTACK_REMOTE_REPLAY_CAPABILITIES' => $headers[DatabaseRemoteReplayChallengeSigner::CAPABILITIES_HEADER] ?? '',
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
            challengeId: 'challenge-health',
            challengeNonce: 'nonce-health',
            requestedAt: '2026-08-29T20:10:02+00:00',
            currentNodeId: 'node-b',
            sourceNodeId: 'node-a',
            keyHash: hash('sha256', 'mutation-challenge-health'),
            requestId: 'req-health',
            connectionName: 'primary',
            logicalTarget: 'users',
            operationFingerprint: 'ofp-health',
            confirmationFingerprint: 'cfp-health',
            validationMode: 'require',
        ));

        self::assertSame('verified', $result->status);
        self::assertSame('http://node-a.internal/_volt/db/remote-replay/challenge', $result->details['endpoint'] ?? null);
        self::assertSame('health_advertisement', $result->details['endpoint_strategy'] ?? null);
        self::assertSame('remote_replay_node_challenge_v1', $result->details['response_protocol'] ?? null);
    }

    public function test_http_challenger_rejects_stale_health_advertisement_when_policy_requires_freshness(): void
    {
        $signer = new DatabaseRemoteReplayChallengeSigner(new Application($this->basePath . DIRECTORY_SEPARATOR . 'requester-stale'));

        $challenger = new HttpDatabaseRemoteReplayChallenger(
            signer: $signer,
            endpointResolver: new DatabaseRemoteReplayChallengeEndpointResolver(
                endpointMap: [],
                healthDiscoveryMode: 'require',
                healthAdvertisementMaxAgeSeconds: 30,
                advertisedEndpointProvider: static fn(): array => [
                    'node-a' => [
                        'endpoint' => 'http://node-a.internal/_volt/db/remote-replay/challenge',
                        'generated_at' => '2026-08-29T20:20:00+00:00',
                    ],
                ],
                clock: static fn(): \DateTimeImmutable => new \DateTimeImmutable('2026-08-29T20:21:00+00:00'),
            ),
            requestTimeoutMs: 1000,
            sender: static function (string $endpoint, array $payload, array $headers, int $timeoutMs): array {
                self::fail('Stale health advertisement should not be used to dispatch a challenge.');
            },
        );

        $result = $challenger->challenge(new DatabaseRemoteReplayChallengeRequest(
            challengeId: 'challenge-stale',
            challengeNonce: 'nonce-stale',
            requestedAt: '2026-08-29T20:21:00+00:00',
            currentNodeId: 'node-b',
            sourceNodeId: 'node-a',
            keyHash: hash('sha256', 'mutation-challenge-stale'),
            requestId: 'req-stale',
            connectionName: 'primary',
            logicalTarget: 'users',
            operationFingerprint: 'ofp-stale',
            confirmationFingerprint: 'cfp-stale',
            validationMode: 'require',
        ));

        self::assertSame('unavailable', $result->status);
        self::assertSame('node-a', $result->details['source_node_id'] ?? null);
        self::assertSame('stale_advertisement', $result->details['status'] ?? null);
        self::assertSame('stale', $result->details['advertisement_freshness'] ?? null);
    }

    public function test_http_challenger_accepts_rotating_key_ids_during_rollout(): void
    {
        $requesterApp = new Application($this->basePath . DIRECTORY_SEPARATOR . 'requester-rotation');
        $requesterConfig = $requesterApp->make(ConfigRepository::class);
        $requesterConfig->set('database.idempotency.node_id', 'node-b');
        $requesterConfig->set('database.idempotency.remote_replay_challenge.key_id', 'key-2026-08');
        $requesterConfig->set('database.idempotency.remote_replay_challenge.shared_secret_map', [
            'key-2026-08' => 'secret-august',
            'key-2026-09' => 'secret-september',
        ]);

        $responderApp = new Application($this->basePath . DIRECTORY_SEPARATOR . 'responder-rotation');
        $responderConfig = $responderApp->make(ConfigRepository::class);
        $responderConfig->set('database.idempotency.node_id', 'node-a');
        $responderConfig->set('database.idempotency.remote_replay_challenge.key_id', 'key-2026-09');
        $responderConfig->set('database.idempotency.remote_replay_challenge.shared_secret_map', [
            'key-2026-08' => 'secret-august',
            'key-2026-09' => 'secret-september',
        ]);

        $requesterSigner = new DatabaseRemoteReplayChallengeSigner($requesterApp);
        $responderSigner = new DatabaseRemoteReplayChallengeSigner($responderApp);

        $store = new InMemoryDatabaseIdempotencyStore();
        $controller = new DatabaseRemoteReplayChallengeController($responderApp, $store, $responderSigner);

        $record = new DatabaseIdempotencyRecord(
            keyHash: hash('sha256', 'mutation-challenge-rotation'),
            operationFingerprint: 'ofp-rotation',
            requestId: 'req-rotation',
            connectionName: 'primary',
            logicalTarget: 'users',
            createdAt: '2026-08-27T12:00:00+00:00',
            nodeId: 'node-a',
            status: 'pending',
        );
        $store->acquire($record);
        $store->complete($record, [
            'confirmation_fingerprint' => 'cfp-rotation',
            'result_summary' => ['result_type' => 'success_no_rows'],
        ]);

        $challenger = new HttpDatabaseRemoteReplayChallenger(
            signer: $requesterSigner,
            endpointResolver: new DatabaseRemoteReplayChallengeEndpointResolver(
                endpointMap: ['node-a' => 'http://node-a.internal/_volt/db/remote-replay/challenge'],
            ),
            sender: function (string $endpoint, array $payload, array $headers, int $timeoutMs) use ($controller): array {
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
                        'HTTP_X_VOLTSTACK_REMOTE_REPLAY_PROTOCOL' => $headers[DatabaseRemoteReplayChallengeSigner::PROTOCOL_HEADER] ?? '',
                        'HTTP_X_VOLTSTACK_REMOTE_REPLAY_KEY_ID' => $headers[DatabaseRemoteReplayChallengeSigner::KEY_ID_HEADER] ?? '',
                        'HTTP_X_VOLTSTACK_REMOTE_REPLAY_CAPABILITIES' => $headers[DatabaseRemoteReplayChallengeSigner::CAPABILITIES_HEADER] ?? '',
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
            challengeId: 'challenge-rotation',
            challengeNonce: 'nonce-rotation',
            requestedAt: '2026-08-27T12:00:01+00:00',
            currentNodeId: 'node-b',
            sourceNodeId: 'node-a',
            keyHash: hash('sha256', 'mutation-challenge-rotation'),
            requestId: 'req-rotation',
            connectionName: 'primary',
            logicalTarget: 'users',
            operationFingerprint: 'ofp-rotation',
            confirmationFingerprint: 'cfp-rotation',
            validationMode: 'require',
        ));

        self::assertSame('verified', $result->status);
        self::assertSame('key-2026-09', $result->details['response_key_id'] ?? null);
        self::assertSame('verified', $result->details['response_signature_verification'] ?? null);
    }

    public function test_http_challenger_reports_protocol_incompatibility(): void
    {
        $requesterApp = new Application($this->basePath . DIRECTORY_SEPARATOR . 'requester-protocol');
        $requesterConfig = $requesterApp->make(ConfigRepository::class);
        $requesterConfig->set('app.key', 'cluster-secret');
        $requesterConfig->set('database.idempotency.remote_replay_challenge.protocol', 'remote_replay_node_challenge_v1');
        $requesterConfig->set('database.idempotency.remote_replay_challenge.supported_protocols', [
            'remote_replay_node_challenge_v1',
        ]);

        $responderApp = new Application($this->basePath . DIRECTORY_SEPARATOR . 'responder-protocol');
        $responderConfig = $responderApp->make(ConfigRepository::class);
        $responderConfig->set('app.key', 'cluster-secret');
        $responderConfig->set('database.idempotency.node_id', 'node-a');
        $responderConfig->set('database.idempotency.remote_replay_challenge.protocol', 'remote_replay_node_challenge_v2');
        $responderConfig->set('database.idempotency.remote_replay_challenge.supported_protocols', [
            'remote_replay_node_challenge_v2',
        ]);

        $challenger = new HttpDatabaseRemoteReplayChallenger(
            signer: new DatabaseRemoteReplayChallengeSigner($requesterApp),
            endpointResolver: new DatabaseRemoteReplayChallengeEndpointResolver(
                endpointMap: ['node-a' => 'http://node-a.internal/_volt/db/remote-replay/challenge'],
            ),
            sender: function (string $endpoint, array $payload, array $headers, int $timeoutMs) use ($responderApp): array {
                $controller = new DatabaseRemoteReplayChallengeController(
                    $responderApp,
                    new InMemoryDatabaseIdempotencyStore(),
                    new DatabaseRemoteReplayChallengeSigner($responderApp),
                );

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
                        'HTTP_X_VOLTSTACK_REMOTE_REPLAY_PROTOCOL' => $headers[DatabaseRemoteReplayChallengeSigner::PROTOCOL_HEADER] ?? '',
                        'HTTP_X_VOLTSTACK_REMOTE_REPLAY_KEY_ID' => $headers[DatabaseRemoteReplayChallengeSigner::KEY_ID_HEADER] ?? '',
                        'HTTP_X_VOLTSTACK_REMOTE_REPLAY_CAPABILITIES' => $headers[DatabaseRemoteReplayChallengeSigner::CAPABILITIES_HEADER] ?? '',
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
            challengeId: 'challenge-protocol',
            challengeNonce: 'nonce-protocol',
            requestedAt: '2026-08-27T12:15:01+00:00',
            currentNodeId: 'node-b',
            sourceNodeId: 'node-a',
            keyHash: hash('sha256', 'mutation-challenge-protocol'),
            requestId: 'req-protocol',
            connectionName: 'primary',
            logicalTarget: 'users',
            operationFingerprint: 'ofp-protocol',
            confirmationFingerprint: 'cfp-protocol',
            validationMode: 'require',
        ));

        self::assertSame('rejected', $result->status);
        self::assertSame('incompatible', $result->details['protocol_compatibility'] ?? null);
        self::assertSame('response_protocol_unsupported', $result->details['protocol_negotiation_reason'] ?? null);
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
                'HTTP_X_VOLTSTACK_REMOTE_REPLAY_PROTOCOL' => $signer->protocol(),
                'HTTP_X_VOLTSTACK_REMOTE_REPLAY_KEY_ID' => $signer->activeKeyId(),
                'HTTP_X_VOLTSTACK_REMOTE_REPLAY_SIGNATURE' => 'invalid-signature',
            ],
            json_encode($payload, JSON_THROW_ON_ERROR),
        );
        $response = $controller($request);
        $body = json_decode($response->content(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(401, $response->statusCode());
        self::assertIsArray($body);
        self::assertSame('rejected', $body['status'] ?? null);
        self::assertTrue($signer->verifyResponse(
            $body,
            (string) $response->headers()[DatabaseRemoteReplayChallengeSigner::SIGNATURE_HEADER],
            (string) ($response->headers()[DatabaseRemoteReplayChallengeSigner::KEY_ID_HEADER] ?? ''),
        ));
    }

    public function test_http_challenger_rejects_invalid_response_signature(): void
    {
        $app = new Application($this->basePath);
        $app->make(ConfigRepository::class)->set('app.key', 'cluster-secret');

        $signer = new DatabaseRemoteReplayChallengeSigner($app);
        $challenger = new HttpDatabaseRemoteReplayChallenger(
            signer: $signer,
            endpointResolver: new DatabaseRemoteReplayChallengeEndpointResolver(
                endpointMap: ['node-a' => 'http://node-a.internal/_volt/db/remote-replay/challenge'],
            ),
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