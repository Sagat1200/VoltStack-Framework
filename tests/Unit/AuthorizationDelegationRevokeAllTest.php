<?php

declare(strict_types=1);

namespace VoltStack\Test\Unit;

use PHPUnit\Framework\TestCase;
use Quantum\Authorization\Authority\InMemoryAuthorityRepository;
use Quantum\Authorization\Authority\Role;
use Quantum\Authorization\Authority\Scope;
use Quantum\Authorization\Contracts\AuthorizationConsistencyInterface;

final class AuthorizationDelegationRevokeAllTest extends TestCase
{
    public function test_revoke_all_emits_single_delegation_revoke_all_bump(): void
    {
        $spy = new class implements AuthorizationConsistencyInterface {
            /** @var list<array{principal_id:?string,scope:string|null,reason:string|null}> */
            public array $authorityCalls = [];

            public function authorityVersion(string $principalId, Scope|string $scope = Scope::GLOBAL): string
            {
                return 'v1';
            }

            public function relationshipVersion(string $principalId, Scope|string $scope = Scope::GLOBAL): string
            {
                return 'v1';
            }

            public function invalidateAuthority(?string $principalId = null, Scope|string|null $scope = null, ?string $reason = null): array
            {
                $this->authorityCalls[] = [
                    'principal_id' => $principalId,
                    'scope' => $scope === null ? null : (string) $scope,
                    'reason' => $reason,
                ];

                return [];
            }

            public function invalidateRelationships(?string $principalId = null, Scope|string|null $scope = null, ?string $reason = null): array
            {
                return [];
            }

            public function inspect(): array
            {
                return [];
            }

            public function flushMetrics(): array
            {
                return [
                    'bump_counters_by_segment' => [],
                    'last_bump_reasons_by_segment' => [],
                    'last_bump_timestamps_by_segment' => [],
                    'total_bumps' => 0,
                ];
            }
        };

        $repo = new InMemoryAuthorityRepository([], $spy);
        $role = new Role('editor');

        $repo->grantDelegation('trustee_1', 'grantor_1', $role, Scope::GLOBAL);
        $repo->grantDelegation('trustee_1', 'grantor_2', $role, 'tenant:acme');

        $count = $repo->revokeAllDelegations(trusteeId: 'trustee_1');

        self::assertSame(2, $count);

        // The last bump must be the single delegation.revoke_all reason.
        $reasons = array_column($spy->authorityCalls, 'reason');
        self::assertSame('delegation.revoke_all', end($reasons));
        self::assertSame(1, count(array_filter($reasons, static fn (string $r): bool => $r === 'delegation.revoke_all')));
    }

    public function test_revoke_all_no_match_does_not_bump(): void
    {
        $spy = new class implements AuthorizationConsistencyInterface {
            public array $authorityCalls = [];

            public function authorityVersion(string $principalId, Scope|string $scope = Scope::GLOBAL): string { return 'v1'; }

            public function relationshipVersion(string $principalId, Scope|string $scope = Scope::GLOBAL): string { return 'v1'; }

            public function invalidateAuthority(?string $principalId = null, Scope|string|null $scope = null, ?string $reason = null): array
            {
                $this->authorityCalls[] = $reason;

                return [];
            }

            public function invalidateRelationships(?string $principalId = null, Scope|string|null $scope = null, ?string $reason = null): array { return []; }

            public function inspect(): array { return []; }

            public function flushMetrics(): array
            {
                return [
                    'bump_counters_by_segment' => [],
                    'last_bump_reasons_by_segment' => [],
                    'last_bump_timestamps_by_segment' => [],
                    'total_bumps' => 0,
                ];
            }
        };

        $repo = new InMemoryAuthorityRepository([], $spy);

        $count = $repo->revokeAllDelegations(trusteeId: 'nobody');

        self::assertSame(0, $count);
        self::assertSame([], $spy->authorityCalls);
    }
}
