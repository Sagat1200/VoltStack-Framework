<?php

declare(strict_types=1);

namespace VoltStack\Test\Unit;

use PHPUnit\Framework\TestCase;
use Quantum\Database\Operation\Engine\DatabaseRemoteReplayChallengeEndpointResolver;

final class DatabaseRemoteReplayChallengeEndpointResolverTest extends TestCase
{
    public function test_it_resolves_endpoint_from_static_map(): void
    {
        $resolver = new DatabaseRemoteReplayChallengeEndpointResolver(
            endpointMap: ['node-a' => 'https://node-a.internal/_volt/db/remote-replay/challenge'],
        );

        $resolution = $resolver->resolve('node-a');

        self::assertSame('resolved', $resolution->status);
        self::assertSame('endpoint_map', $resolution->strategy);
        self::assertSame('https://node-a.internal/_volt/db/remote-replay/challenge', $resolution->endpoint);
    }

    public function test_it_resolves_endpoint_from_template(): void
    {
        $resolver = new DatabaseRemoteReplayChallengeEndpointResolver(
            endpointMap: [],
            endpointTemplate: 'https://cluster.internal/{node_id}{path}',
            defaultPath: '/_volt/db/remote-replay/challenge',
        );

        $resolution = $resolver->resolve('node-b');

        self::assertSame('resolved', $resolution->status);
        self::assertSame('endpoint_template', $resolution->strategy);
        self::assertSame('https://cluster.internal/node-b/_volt/db/remote-replay/challenge', $resolution->endpoint);
    }

    public function test_it_reports_diagnostics_for_known_nodes(): void
    {
        $resolver = new DatabaseRemoteReplayChallengeEndpointResolver(
            endpointMap: ['node-a' => 'https://node-a.internal/_volt/db/remote-replay/challenge'],
            endpointTemplate: 'https://cluster.internal/{node_id}{path}',
            defaultPath: '/_volt/db/remote-replay/challenge',
            knownNodes: ['node-b', 'node-c'],
        );

        $diagnostics = $resolver->diagnostics('node-a');

        self::assertSame('node-a', $diagnostics['current_node_id']);
        self::assertSame(3, $diagnostics['configured_nodes']);
        self::assertSame(3, $diagnostics['resolved_count']);
        self::assertContains('node-b', $diagnostics['resolved_nodes']);
        self::assertIsArray($diagnostics['resolutions']);
    }

    public function test_it_resolves_endpoint_from_health_advertisement(): void
    {
        $resolver = new DatabaseRemoteReplayChallengeEndpointResolver(
            endpointMap: [],
            advertisedEndpointProvider: static fn(): array => [
                'node-a' => [
                    'endpoint' => 'https://node-a.example.com/_volt/db/remote-replay/challenge',
                    'protocol' => 'remote_replay_node_challenge_v1',
                    'key_id' => 'key-2026-09',
                ],
            ],
        );

        $resolution = $resolver->resolve('node-a');

        self::assertSame('resolved', $resolution->status);
        self::assertSame('health_advertisement', $resolution->strategy);
        self::assertSame('https://node-a.example.com/_volt/db/remote-replay/challenge', $resolution->endpoint);
        self::assertSame('remote_replay_node_challenge_v1', $resolution->details['protocol'] ?? null);
        self::assertSame('key-2026-09', $resolution->details['key_id'] ?? null);
    }
}
