<?php

declare(strict_types=1);

namespace VoltStack\Test\Unit;

use PHPUnit\Framework\TestCase;
use Quantum\Authorization\Authority\Scope;
use Quantum\Authorization\Consistency\VersionedAuthorizationConsistency;
use Quantum\Cache\LocalVersionAuthority;

final class AuthorizationConsistencyFlushMetricsTest extends TestCase
{
    public function test_flush_metrics_returns_four_keys_and_total_bumps_sum(): void
    {
        $consistency = new VersionedAuthorizationConsistency(new LocalVersionAuthority());

        $consistency->invalidateAuthority('u_1', Scope::GLOBAL, 'delegation.grant');
        $consistency->invalidateAuthority('u_1', 'tenant:acme', 'delegation.revoke');
        $consistency->invalidateRelationships('u_2', Scope::GLOBAL, 'relationship.change');

        $metrics = $consistency->flushMetrics();

        self::assertArrayHasKey('bump_counters_by_segment', $metrics);
        self::assertArrayHasKey('last_bump_reasons_by_segment', $metrics);
        self::assertArrayHasKey('last_bump_timestamps_by_segment', $metrics);
        self::assertArrayHasKey('total_bumps', $metrics);

        $counters = $metrics['bump_counters_by_segment'];
        self::assertIsArray($counters);

        $total = 0;
        foreach ($counters as $count) {
            self::assertIsInt($count);
            $total += $count;
        }

        self::assertSame($total, $metrics['total_bumps']);
        self::assertGreaterThanOrEqual(3, $metrics['total_bumps']);
    }

    public function test_flush_metrics_reasons_match_segments(): void
    {
        $consistency = new VersionedAuthorizationConsistency(new LocalVersionAuthority());

        $consistency->invalidateAuthority(null, null, 'delegation.grant');

        $metrics = $consistency->flushMetrics();

        self::assertArrayHasKey('authority.global', $metrics['last_bump_reasons_by_segment']);
        self::assertSame('delegation.grant', $metrics['last_bump_reasons_by_segment']['authority.global']);
    }
}
