<?php

declare(strict_types=1);

namespace VoltStack\Test\Unit;

use PHPUnit\Framework\TestCase;
use Quantum\Config\ConfigRepository;
use Quantum\Database\Operation\Contracts\DatabaseHealthStoreInterface;
use Quantum\Database\Operation\Contracts\DatabaseRemoteReplayChallengerInterface;
use Quantum\Database\Operation\DatabaseTelemetryReport;
use Quantum\Database\Operation\Engine\HttpDatabaseRemoteReplayChallenger;
use Quantum\Database\Operation\Engine\NullDatabaseRemoteReplayChallenger;
use VoltStack\Framework\Application;
use VoltStack\Framework\Provider\DatabaseServiceProvider;

final class DatabaseRemoteReplayChallengerBindingTest extends TestCase
{
    private string $basePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->basePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'voltstack-db-remote-replay-binding-' . uniqid('', true);
        mkdir($this->basePath, 0777, true);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->basePath)) {
            rmdir($this->basePath);
        }

        parent::tearDown();
    }

    public function test_it_resolves_http_challenger_when_transport_and_endpoint_map_are_configured(): void
    {
        $app = new Application($this->basePath);
        $app->register(DatabaseServiceProvider::class);
        $config = $app->make(ConfigRepository::class);
        $config->set('database.idempotency.remote_replay_challenge.transport', 'http');
        $config->set('database.idempotency.remote_replay_challenge.endpoint_map', [
            'node-a' => 'http://node-a.internal/_volt/db/remote-replay/challenge',
        ]);

        $challenger = $app->make(DatabaseRemoteReplayChallengerInterface::class);

        self::assertInstanceOf(HttpDatabaseRemoteReplayChallenger::class, $challenger);
    }

    public function test_it_resolves_http_challenger_when_template_is_configured_in_auto_mode(): void
    {
        $app = new Application($this->basePath);
        $app->register(DatabaseServiceProvider::class);
        $config = $app->make(ConfigRepository::class);
        $config->set('database.idempotency.remote_replay_challenge.transport', 'auto');
        $config->set('database.idempotency.remote_replay_challenge.endpoint_template', 'https://cluster.internal/{node_id}{path}');

        $challenger = $app->make(DatabaseRemoteReplayChallengerInterface::class);

        self::assertInstanceOf(HttpDatabaseRemoteReplayChallenger::class, $challenger);
    }

    public function test_it_falls_back_to_null_challenger_without_endpoint_map(): void
    {
        $app = new Application($this->basePath);
        $app->register(DatabaseServiceProvider::class);
        $config = $app->make(ConfigRepository::class);
        $config->set('database.idempotency.remote_replay_challenge.transport', 'auto');
        $config->set('database.idempotency.remote_replay_challenge.discovery_via_health', false);

        $challenger = $app->make(DatabaseRemoteReplayChallengerInterface::class);

        self::assertInstanceOf(NullDatabaseRemoteReplayChallenger::class, $challenger);
    }

    public function test_it_resolves_http_challenger_in_auto_mode_when_health_discovery_is_enabled(): void
    {
        $app = new Application($this->basePath);
        $app->register(DatabaseServiceProvider::class);
        $config = $app->make(ConfigRepository::class);
        $config->set('database.idempotency.remote_replay_challenge.transport', 'auto');
        $config->set('database.idempotency.remote_replay_challenge.discovery_via_health', true);

        $store = $app->make(DatabaseHealthStoreInterface::class);
        $store->persist(new DatabaseTelemetryReport(
            requestId: 'req-health',
            tenantId: null,
            traceId: null,
            generatedAt: '2026-08-29T20:00:00+00:00',
            summary: [
                'remote_replay_challenge' => [
                    'cluster_advertisement' => [
                        'node_id' => 'node-a',
                        'endpoint' => 'https://node-a.internal/_volt/db/remote-replay/challenge',
                    ],
                ],
            ],
            health: [],
            nodeId: 'node-a',
        ));

        $challenger = $app->make(DatabaseRemoteReplayChallengerInterface::class);

        self::assertInstanceOf(HttpDatabaseRemoteReplayChallenger::class, $challenger);
    }
}
