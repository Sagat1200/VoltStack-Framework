<?php

declare(strict_types=1);

namespace VoltStack\Test\Unit;

use PHPUnit\Framework\TestCase;
use Quantum\Cache\CacheVersionAuthority;
use Quantum\Cache\FileStore;
use Quantum\Cache\MemoryStore;

final class CacheVersionAuthorityTest extends TestCase
{
    private string $storagePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->storagePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'voltstack-cache-version-authority-' . uniqid('', true);
        @mkdir($this->storagePath, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->storagePath);

        parent::tearDown();
    }

    public function test_current_version_defaults_to_v1_when_scope_is_missing(): void
    {
        $authority = new CacheVersionAuthority(new MemoryStore());

        self::assertSame('v1', $authority->currentVersion('authorization.consistency.authority.global'));
    }

    public function test_bump_persists_version_across_separate_instances_within_memory_store(): void
    {
        $store = new MemoryStore();
        $first = new CacheVersionAuthority($store, 'shared.prefix');
        $second = new CacheVersionAuthority($store, 'shared.prefix');
        $scope = 'authorization.consistency.authority.principal_scope.abc123';

        self::assertSame('v1', $first->currentVersion($scope));
        self::assertSame('v2', $first->bump($scope));
        self::assertSame('v2', $second->currentVersion($scope));
        self::assertSame('v3', $second->bump($scope));
        self::assertSame('v3', $first->currentVersion($scope));
    }

    public function test_bump_persists_version_across_separate_instances_via_file_store(): void
    {
        $store = new FileStore($this->storagePath, 'test-prefix');
        $first = new CacheVersionAuthority($store, 'shared.prefix');
        $second = new CacheVersionAuthority(new FileStore($this->storagePath, 'test-prefix'), 'shared.prefix');
        $scope = 'authorization.consistency.relationships.scope.tenant-x';

        self::assertSame('v1', $first->currentVersion($scope));
        self::assertSame('v2', $first->bump($scope));
        self::assertSame('v2', $second->currentVersion($scope));
        self::assertSame('v3', $second->bump($scope));
        self::assertSame('v3', $first->currentVersion($scope));
    }

    public function test_empty_key_prefix_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('CacheVersionAuthority key prefix cannot be empty.');

        new CacheVersionAuthority(new MemoryStore(), '   ');
    }

    public function test_exposes_store_and_prefix(): void
    {
        $store = new MemoryStore();
        $authority = new CacheVersionAuthority($store, 'ns.authz.versions');

        self::assertSame($store, $authority->store());
        self::assertSame('ns.authz.versions', $authority->keyPrefix());
    }

    private function removeDirectory(string $directory): void
    {
        if (! is_dir($directory)) {
            return;
        }

        $items = scandir($directory);

        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $directory . DIRECTORY_SEPARATOR . $item;

            if (is_dir($path)) {
                $this->removeDirectory($path);
                continue;
            }

            @unlink($path);
        }

        @rmdir($directory);
    }
}
