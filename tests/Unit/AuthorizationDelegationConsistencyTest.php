<?php

declare(strict_types=1);

namespace VoltStack\Test\Unit;

use PHPUnit\Framework\TestCase;
use Quantum\Authorization\Authority\InMemoryAuthorityRepository;
use Quantum\Authorization\Authority\Permission;
use Quantum\Authorization\Authority\Role;
use Quantum\Authorization\Authority\Scope;
use Quantum\Authorization\Contracts\AuthorizationConsistencyInterface;
use VoltStack\Framework\Application;

final class AuthorizationDelegationConsistencyTest extends TestCase
{
    public function test_delegation_grant_invalidates_authority_consistency_with_reason(): void
    {
        $app = new Application(sys_get_temp_dir());
        /** @var AuthorizationConsistencyInterface $consistency */
        $consistency = $app->make(AuthorizationConsistencyInterface::class);

        $before = $consistency->inspect();

        $repository = new InMemoryAuthorityRepository([], $consistency);
        $repository->grantDelegation('t_1', 'g_1', Permission::from('posts.publish'), 'tenant:acme');

        $after = $consistency->inspect();

        $beforeCounters = $before['bump_counters_by_segment'] ?? [];
        $afterCounters = $after['bump_counters_by_segment'] ?? [];

        $reasons = [];
        $bySegment = $after['last_bump_reasons_by_segment'] ?? [];
        if (is_array($bySegment)) {
            foreach ($bySegment as $segmentReasons) {
                $segmentReasons = is_array($segmentReasons) ? $segmentReasons : [$segmentReasons];
                foreach ($segmentReasons as $reason) {
                    if (is_string($reason)) {
                        $reasons[] = $reason;
                    }
                }
            }
        }

        self::assertNotEmpty($afterCounters);
        self::assertNotSame($beforeCounters, $afterCounters);
        self::assertContains('delegation.grant', $reasons);
    }

    public function test_delegation_revoke_invalidates_authority_consistency_with_reason(): void
    {
        $app = new Application(sys_get_temp_dir());
        /** @var AuthorizationConsistencyInterface $consistency */
        $consistency = $app->make(AuthorizationConsistencyInterface::class);

        $repository = new InMemoryAuthorityRepository([], $consistency);
        $repository->grantDelegation('t_2', 'g_2', new Role('editor', [Permission::from('posts.edit')]), Scope::GLOBAL);

        $before = $consistency->inspect();
        $repository->revokeDelegation('t_2', 'g_2', new Role('editor'), Scope::GLOBAL);
        $after = $consistency->inspect();

        $bySegment = $after['last_bump_reasons_by_segment'] ?? [];
        $reasons = [];
        if (is_array($bySegment)) {
            foreach ($bySegment as $segmentReasons) {
                $segmentReasons = is_array($segmentReasons) ? $segmentReasons : [$segmentReasons];
                foreach ($segmentReasons as $reason) {
                    if (is_string($reason)) {
                        $reasons[] = $reason;
                    }
                }
            }
        }

        self::assertNotSame($before, $after);
        self::assertContains('delegation.revoke', $reasons);
    }

    public function test_consistency_doctor_payload_includes_bump_prefix_counters_via_json_report(): void
    {
        $app = new Application(sys_get_temp_dir());
        /** @var AuthorizationConsistencyInterface $consistency */
        $consistency = $app->make(AuthorizationConsistencyInterface::class);

        // Manual bumps emulating different subsystems
        $consistency->invalidateAuthority('u_1', 'tenant:x', 'delegation.grant');
        $consistency->invalidateAuthority('u_1', 'tenant:x', 'delegation.revoke');
        $consistency->invalidateAuthority('u_2', null, 'service.resolved');

        $spy = new class ($consistency) implements AuthorizationConsistencyInterface {
            public function __construct(private readonly AuthorizationConsistencyInterface $inner) {}

            public function authorityVersion(string $principalId, \Quantum\Authorization\Authority\Scope|string $scope = \Quantum\Authorization\Authority\Scope::GLOBAL): string
            {
                return $this->inner->authorityVersion($principalId, $scope);
            }

            public function relationshipVersion(string $principalId, \Quantum\Authorization\Authority\Scope|string $scope = \Quantum\Authorization\Authority\Scope::GLOBAL): string
            {
                return $this->inner->relationshipVersion($principalId, $scope);
            }

            public function invalidateAuthority(?string $principalId = null, \Quantum\Authorization\Authority\Scope|string|null $scope = null, ?string $reason = null): array
            {
                return $this->inner->invalidateAuthority($principalId, $scope, $reason);
            }

            public function invalidateRelationships(?string $principalId = null, \Quantum\Authorization\Authority\Scope|string|null $scope = null, ?string $reason = null): array
            {
                return $this->inner->invalidateRelationships($principalId, $scope, $reason);
            }

            public function inspect(): array
            {
                return $this->inner->inspect();
            }
        };

        $inspect = $spy->inspect();
        $reasonsFlat = [];
        $bySegment = $inspect['last_bump_reasons_by_segment'] ?? [];
        if (is_array($bySegment)) {
            foreach ($bySegment as $segment => $list) {
                $list = is_array($list) ? $list : [$list];
                foreach ($list as $reason) {
                    if (is_string($reason) && trim($reason) !== '') {
                        $reasonsFlat[] = $reason;
                    }
                }
            }
        }

        $delegationCount = 0;
        $serviceCount = 0;
        foreach ($reasonsFlat as $reason) {
            if (str_starts_with($reason, 'delegation.')) {
                $delegationCount++;
            }
            if (str_starts_with($reason, 'service.')) {
                $serviceCount++;
            }
        }

        self::assertGreaterThanOrEqual(2, $delegationCount);
        self::assertGreaterThanOrEqual(1, $serviceCount);
    }
}
