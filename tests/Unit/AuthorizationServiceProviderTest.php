<?php

declare(strict_types=1);

namespace VoltStack\Test\Unit;

use PHPUnit\Framework\TestCase;
use Quantum\Authorization\AuthorizationDriverRegistry;
use Quantum\Authorization\Authority\Permission;
use Quantum\Authorization\Authority\Role;
use Quantum\Authorization\Authority\Scope;
use Quantum\Authorization\Manifest\Contracts\AuthorizationManifestStoreInterface;
use Quantum\Authorization\Manifest\FilesystemAuthorizationManifestStore;
use Quantum\Authorization\Manifest\InMemoryAuthorizationManifestStore;
use Quantum\Authorization\Core\Stages\AdaptiveAccessStage;
use Quantum\Authorization\Contracts\AuthorizationConsistencyInterface;
use Quantum\Authorization\Contracts\AuthorityAdministrationInterface;
use Quantum\Authorization\Contracts\AuthorityRepositoryInterface;
use Quantum\Authorization\Contracts\AuthorizationMetadataResolverInterface;
use Quantum\Authorization\Contracts\RelationshipAdministrationInterface;
use Quantum\Authorization\Contracts\RelationshipRepositoryInterface;
use Quantum\Authorization\Metadata\AuthorizationMetadataResolver;
use Quantum\Config\ConfigRepository;
use VoltStack\Framework\Application;

final class AuthorizationServiceProviderTest extends TestCase
{
    public function test_manifest_store_defaults_to_in_memory_when_no_path_configured(): void
    {
        $app = new Application(sys_get_temp_dir());

        $store = $app->make(AuthorizationManifestStoreInterface::class);

        self::assertInstanceOf(InMemoryAuthorizationManifestStore::class, $store);
    }

    public function test_manifest_store_uses_filesystem_when_path_configured(): void
    {
        $app = new Application(sys_get_temp_dir());
        $tempDir = sys_get_temp_dir() . '/voltstack_authz_provider_' . uniqid();
        @mkdir($tempDir, 0777, true);

        try {
            /** @var ConfigRepository $config */
            $config = $app->make(ConfigRepository::class);
            $config->set('authorization.manifest.path', $tempDir);

            $store = $app->make(AuthorizationManifestStoreInterface::class);

            self::assertInstanceOf(FilesystemAuthorizationManifestStore::class, $store);
        } finally {
            $this->cleanupDir($tempDir);
        }
    }

    public function test_resolver_receives_null_store_when_manifest_disabled(): void
    {
        $app = new Application(sys_get_temp_dir());

        /** @var ConfigRepository $config */
        $config = $app->make(ConfigRepository::class);
        $config->set('authorization.manifest.enabled', false);

        $resolver = $app->make(AuthorizationMetadataResolverInterface::class);

        self::assertInstanceOf(AuthorizationMetadataResolver::class, $resolver);

        $storeProperty = new \ReflectionProperty(AuthorizationMetadataResolver::class, 'manifestStore');
        $storeProperty->setAccessible(true);
        $storeValue = $storeProperty->getValue($resolver);

        self::assertNull($storeValue);
    }

    public function test_resolver_receives_in_memory_store_by_default(): void
    {
        $app = new Application(sys_get_temp_dir());

        $resolver = $app->make(AuthorizationMetadataResolverInterface::class);

        self::assertInstanceOf(AuthorizationMetadataResolver::class, $resolver);

        $storeProperty = new \ReflectionProperty(AuthorizationMetadataResolver::class, 'manifestStore');
        $storeProperty->setAccessible(true);
        $storeValue = $storeProperty->getValue($resolver);

        self::assertNotNull($storeValue);
        self::assertInstanceOf(InMemoryAuthorizationManifestStore::class, $storeValue);
    }

    public function test_resolver_receives_filesystem_store_when_path_configured(): void
    {
        $app = new Application(sys_get_temp_dir());
        $tempDir = sys_get_temp_dir() . '/voltstack_authz_provider_fs_' . uniqid();
        @mkdir($tempDir, 0777, true);

        try {
            /** @var ConfigRepository $config */
            $config = $app->make(ConfigRepository::class);
            $config->set('authorization.manifest.path', $tempDir);

            $resolver = $app->make(AuthorizationMetadataResolverInterface::class);

            self::assertInstanceOf(AuthorizationMetadataResolver::class, $resolver);

            $storeProperty = new \ReflectionProperty(AuthorizationMetadataResolver::class, 'manifestStore');
            $storeProperty->setAccessible(true);
            $storeValue = $storeProperty->getValue($resolver);

            self::assertNotNull($storeValue);
            self::assertInstanceOf(FilesystemAuthorizationManifestStore::class, $storeValue);
        } finally {
            $this->cleanupDir($tempDir);
        }
    }

    public function test_manifest_enabled_zero_boolean_is_treated_as_disabled(): void
    {
        $app = new Application(sys_get_temp_dir());

        /** @var ConfigRepository $config */
        $config = $app->make(ConfigRepository::class);
        $config->set('authorization.manifest.enabled', 0);

        $resolver = $app->make(AuthorizationMetadataResolverInterface::class);

        $storeProperty = new \ReflectionProperty(AuthorizationMetadataResolver::class, 'manifestStore');
        $storeProperty->setAccessible(true);
        $storeValue = $storeProperty->getValue($resolver);

        self::assertNull($storeValue);
    }

    public function test_adaptive_access_stage_resolves_with_default_disabled_configuration(): void
    {
        $app = new Application(sys_get_temp_dir());

        $stage = $app->make(AdaptiveAccessStage::class);

        self::assertInstanceOf(AdaptiveAccessStage::class, $stage);
    }

    public function test_custom_authority_driver_can_be_registered_via_registry(): void
    {
        $app = new Application(sys_get_temp_dir());
        $app->make(ConfigRepository::class)->set('authorization.authority.driver', 'external');
        $app->make(ConfigRepository::class)->set('authorization.authority.memoize', false);

        $driver = new class implements AuthorityAdministrationInterface, AuthorityRepositoryInterface {
            public function listGrants(array $filters = []): array
            {
                return [['principal_id' => 'ext-1', 'scope' => 'global', 'type' => 'role', 'value' => 'external-admin']];
            }

            public function grantRole(string $principalId, Role|string $role, Scope|string $scope = Scope::GLOBAL): bool
            {
                return true;
            }

            public function grantPermission(string $principalId, Permission|string $permission, Scope|string $scope = Scope::GLOBAL): bool
            {
                return true;
            }

            public function revokeRole(string $principalId, Role|string $role, Scope|string $scope = Scope::GLOBAL): bool
            {
                return true;
            }

            public function revokePermission(string $principalId, Permission|string $permission, Scope|string $scope = Scope::GLOBAL): bool
            {
                return true;
            }

            public function rolesForPrincipal(string $principalId, Scope|string $scope = Scope::GLOBAL): array
            {
                return [new Role('external-admin')];
            }

            public function directPermissionsForPrincipal(string $principalId, Scope|string $scope = Scope::GLOBAL): array
            {
                return [];
            }

            public function effectivePermissionsForPrincipal(string $principalId, Scope|string $scope = Scope::GLOBAL): array
            {
                return [];
            }

            public function scopesForPrincipal(string $principalId): array
            {
                return [new Scope('global')];
            }

            public function hasPermission(string $principalId, Permission|string $permission, Scope|string $scope = Scope::GLOBAL): bool
            {
                return false;
            }
        };

        $app->make(AuthorizationDriverRegistry::class)->extendAuthority('external', fn (Application $_app): AuthorityRepositoryInterface => $driver);

        self::assertSame($driver, $app->make(AuthorityRepositoryInterface::class));
        self::assertSame($driver, $app->make(AuthorityAdministrationInterface::class));
    }

    public function test_custom_relationship_driver_can_be_registered_via_registry(): void
    {
        $app = new Application(sys_get_temp_dir());
        $app->make(ConfigRepository::class)->set('authorization.relationships.driver', 'external');

        $driver = new class implements RelationshipAdministrationInterface, RelationshipRepositoryInterface {
            public function hasRelationship(string $principalId, string $relation, mixed $resource, Scope|string $scope = Scope::GLOBAL): bool
            {
                return $principalId === 'ext-1' && $relation === 'owner' && $resource === 'doc-1';
            }

            public function listRelationships(array $filters = []): array
            {
                return [['principal_id' => 'ext-1', 'relation' => 'owner', 'resource_key' => 'doc-1', 'scope' => 'global']];
            }

            public function revokeRelationshipByKey(string $principalId, string $relation, string $resourceKey, Scope|string $scope = Scope::GLOBAL): bool
            {
                return true;
            }
        };

        $app->make(AuthorizationDriverRegistry::class)->extendRelationships('external', fn (Application $_app): RelationshipRepositoryInterface => $driver);

        self::assertSame($driver, $app->make(RelationshipRepositoryInterface::class));
        self::assertSame($driver, $app->make(RelationshipAdministrationInterface::class));
    }

    public function test_custom_consistency_driver_can_be_registered_via_registry(): void
    {
        $app = new Application(sys_get_temp_dir());
        $app->make(ConfigRepository::class)->set('authorization.consistency.driver', 'external');

        $driver = new class implements AuthorizationConsistencyInterface {
            private int $version = 1;

            public function authorityVersion(string $principalId, Scope|string $scope = Scope::GLOBAL): string
            {
                return 'x' . $this->version;
            }

            public function relationshipVersion(string $principalId, Scope|string $scope = Scope::GLOBAL): string
            {
                return 'x' . $this->version;
            }

            public function invalidateAuthority(?string $principalId = null, Scope|string|null $scope = null): array
            {
                $this->version++;

                return ['global' => 'x' . $this->version];
            }

            public function invalidateRelationships(?string $principalId = null, Scope|string|null $scope = null): array
            {
                $this->version++;

                return ['global' => 'x' . $this->version];
            }
        };

        $app->make(AuthorizationDriverRegistry::class)->extendConsistency('external', fn (Application $_app): AuthorizationConsistencyInterface => $driver);

        self::assertSame($driver, $app->make(AuthorizationConsistencyInterface::class));
    }

    private function cleanupDir(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }

        $items = glob($dir . '/*');
        if (is_array($items)) {
            foreach ($items as $item) {
                if (is_file($item)) {
                    @unlink($item);
                }
            }
        }

        @rmdir($dir);
    }
}
