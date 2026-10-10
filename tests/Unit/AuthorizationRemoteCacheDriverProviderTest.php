<?php

declare(strict_types=1);

namespace VoltStack\Test\Unit;

use PHPUnit\Framework\TestCase;
use Quantum\Authorization\AuthorizationDriverRegistry;
use Quantum\Authorization\AuthorizationRemoteCacheDriverProvider;
use Quantum\Authorization\Authority\RemoteCacheAuthorityRepository;
use Quantum\Authorization\Contracts\AuthorizationConsistencyInterface;
use Quantum\Authorization\Contracts\AuthorityAdministrationInterface;
use Quantum\Authorization\Contracts\AuthorityRepositoryInterface;
use Quantum\Authorization\Contracts\RelationshipAdministrationInterface;
use Quantum\Authorization\Contracts\RelationshipRepositoryInterface;
use Quantum\Authorization\Relationship\RemoteCacheRelationshipRepository;
use Quantum\Config\ConfigRepository;
use VoltStack\Framework\Application;

final class AuthorizationRemoteCacheDriverProviderTest extends TestCase
{
    public function test_remote_cache_driver_registers_authority_relationships_and_consistency_via_registry(): void
    {
        $workspace = sys_get_temp_dir() . '\\' . uniqid('driver_registry_', true);
        $app = new Application($workspace);

        /** @var ConfigRepository $config */
        $config = $app->make(ConfigRepository::class);
        $config->set('authorization.authority.driver', AuthorizationRemoteCacheDriverProvider::DRIVER_NAME);
        $config->set('authorization.relationships.driver', AuthorizationRemoteCacheDriverProvider::DRIVER_NAME);
        $config->set('authorization.consistency.driver', AuthorizationRemoteCacheDriverProvider::DRIVER_NAME);

        AuthorizationRemoteCacheDriverProvider::register($app);

        // Registry entries exist before container resolution.
        $registry = $app->make(AuthorizationDriverRegistry::class);
        self::assertNotNull($registry->resolveConsistency(AuthorizationRemoteCacheDriverProvider::DRIVER_NAME, $app));
        self::assertNotNull($registry->resolveAuthority(AuthorizationRemoteCacheDriverProvider::DRIVER_NAME, $app));
        self::assertNotNull($registry->resolveRelationships(AuthorizationRemoteCacheDriverProvider::DRIVER_NAME, $app));

        // Container resolves concrete remote-cache instances through the provider,
        // bypassing the memoization wrapper so we see the driver we registered.
        $authority = $app->make(AuthorizationDriverRegistry::class)
            ->resolveAuthority(AuthorizationRemoteCacheDriverProvider::DRIVER_NAME, $app);
        self::assertInstanceOf(RemoteCacheAuthorityRepository::class, $authority);
        self::assertInstanceOf(AuthorityAdministrationInterface::class, $authority);

        $relationships = $app->make(AuthorizationDriverRegistry::class)
            ->resolveRelationships(AuthorizationRemoteCacheDriverProvider::DRIVER_NAME, $app);
        self::assertInstanceOf(RemoteCacheRelationshipRepository::class, $relationships);
        self::assertInstanceOf(RelationshipAdministrationInterface::class, $relationships);

        $consistency = $app->make(AuthorizationConsistencyInterface::class);
        $inspect = $consistency->inspect();
        self::assertSame(RemoteCacheAuthorityRepository::class, $authority::class);
        self::assertArrayHasKey('version_authority_info', $inspect);
        self::assertSame('cache', $inspect['version_authority_info']['kind'] ?? null);
    }

    public function test_remote_cache_driver_supports_end_to_end_grant_and_consistency_round_trip(): void
    {
        $workspace = sys_get_temp_dir() . '\\' . uniqid('round_trip_authz_', true);
        $app = new Application($workspace);

        /** @var ConfigRepository $config */
        $config = $app->make(ConfigRepository::class);
        $config->set('authorization.authority.driver', AuthorizationRemoteCacheDriverProvider::DRIVER_NAME);
        $config->set('authorization.relationships.driver', AuthorizationRemoteCacheDriverProvider::DRIVER_NAME);
        $config->set('authorization.consistency.driver', AuthorizationRemoteCacheDriverProvider::DRIVER_NAME);
        $config->set('authorization.authority.memoize', false);

        AuthorizationRemoteCacheDriverProvider::register($app, [
            'prefix' => 'round-trip.authz.remote.' . bin2hex(random_bytes(4)),
        ]);

        /** @var AuthorityAdministrationInterface $admin */
        $admin = $app->make(AuthorityAdministrationInterface::class);
        self::assertTrue($admin->grantRole('user-x', 'admin', 'org:demo'));
        self::assertTrue($admin->grantPermission('user-x', 'settings:update', 'org:demo'));

        $repository = $app->make(AuthorityRepositoryInterface::class);
        $effective = $repository->effectivePermissionsForPrincipal('user-x', 'org:demo');
        self::assertCount(1, $effective);
        self::assertSame('settings:update', $effective[0]->name);

        $consistency = $app->make(AuthorizationConsistencyInterface::class);
        $inspect = $consistency->inspect();

        self::assertSame(
            'authority.grant_permission',
            $inspect['last_bump_reasons_by_segment']['authority.principal_scope'] ?? null,
        );
        self::assertGreaterThanOrEqual(2, $inspect['bump_counters_by_segment']['authority.principal'] ?? 0);
    }
}
