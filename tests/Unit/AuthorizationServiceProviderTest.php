<?php

declare(strict_types=1);

namespace VoltStack\Test\Unit;

use PHPUnit\Framework\TestCase;
use Quantum\Authorization\Manifest\Contracts\AuthorizationManifestStoreInterface;
use Quantum\Authorization\Manifest\FilesystemAuthorizationManifestStore;
use Quantum\Authorization\Manifest\InMemoryAuthorizationManifestStore;
use Quantum\Authorization\Contracts\AuthorizationMetadataResolverInterface;
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
