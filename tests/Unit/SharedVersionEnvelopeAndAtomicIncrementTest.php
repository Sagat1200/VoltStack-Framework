<?php

declare(strict_types=1);

namespace VoltStack\Test\Unit;

use PHPUnit\Framework\TestCase;
use Quantum\Cache\CacheVersionAuthority;
use Quantum\Cache\Contracts\AtomicIncrementableStoreInterface;
use Quantum\Cache\FileStore;
use Quantum\Cache\FileVersionAuthority;
use Quantum\Cache\MemoryStore;

final class SharedVersionEnvelopeAndAtomicIncrementTest extends TestCase
{
    private string $storagePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->storagePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'voltstack-shared-envelope-' . uniqid('', true);
        @mkdir($this->storagePath, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->storagePath);

        parent::tearDown();
    }

    public function test_file_version_authority_persists_distributed_audit_metadata(): void
    {
        $first = new FileVersionAuthority($this->storagePath);
        $second = new FileVersionAuthority($this->storagePath);

        self::assertSame('v1', $first->currentVersion('authz.x'));
        self::assertSame('v2', $first->bump('authz.x', 'bulk-sync'));
        self::assertSame('v2', $second->currentVersion('authz.x'));

        $envelope = $second->readEnvelope('authz.x');

        self::assertSame(2, $envelope['version']);
        self::assertSame(1, $envelope['bump_counter']);
        self::assertSame('bulk-sync', $envelope['last_reason']);
        self::assertNotEmpty($envelope['last_bump_at']);
        self::assertSame('authz.x', $envelope['scope']);

        self::assertSame('v3', $second->bump('authz.x', 'admin change'));
        $envelopeAfter = $first->readEnvelope('authz.x');
        self::assertSame(3, $envelopeAfter['version']);
        self::assertSame(2, $envelopeAfter['bump_counter']);
        self::assertSame('admin change', $envelopeAfter['last_reason']);
    }

    public function test_cache_version_authority_upgrades_legacy_schema_and_tracks_audit(): void
    {
        $store = new MemoryStore();
        $authority = new CacheVersionAuthority($store, 'legacy.prefix');
        $scope = 'authz.legacy';
        $key = $this->keyFor('legacy.prefix', $scope);
        // Seed legacy (ENVELOPE_VERSION=1) payload and a competing legacy counter
        // marker to ensure the atomic path does not overwrite prior versioning.
        $store->forever($key, [
            'envelope' => 1,
            'scope' => $scope,
            'version' => 7,
            'updated_at' => '2025-01-01T00:00:00+00:00',
        ]);
        $store->forever($key . '.ctr', 8);

        self::assertSame('v7', $authority->currentVersion($scope));
        self::assertSame('v9', $authority->bump($scope, 'migration'));

        $envelope = $authority->readEnvelope($scope);
        self::assertSame(9, $envelope['version']);
        self::assertSame(8, $envelope['bump_counter']);
        self::assertSame('migration', $envelope['last_reason']);
        self::assertNotEmpty($envelope['last_bump_at']);
    }

    public function test_cache_version_authority_uses_atomic_increment_when_store_supports_it(): void
    {
        $store = $this->createMock(AtomicIncrementableStoreInterface::class);
        $store->method('get')->willReturn(null);
        $matcher = self::exactly(2);
        $store->expects($matcher)
            ->method('incrementInt')
            ->willReturnCallback(function (string $key, int $step, int $initial, mixed $ttl = null) use ($matcher): int {
                $invocation = $matcher->numberOfInvocations();

                if ($invocation === 1) {
                    self::assertSame(2, $initial);

                    return 2;
                }

                self::assertSame(2, $initial);

                return 3;
            });
        $foreverSpy = [];
        $store->method('forever')->willReturnCallback(
            static function (string $key, mixed $value) use (&$foreverSpy): bool {
                $foreverSpy[$key] = $value;

                return true;
            },
        );

        $authority = new CacheVersionAuthority($store, 'atomic.prefix');
        self::assertSame('v2', $authority->bump('authz.atomic', 'first'));
        self::assertSame('v3', $authority->bump('authz.atomic', 'second'));

        $payloadKey = $this->keyFor('atomic.prefix', 'authz.atomic');
        self::assertArrayHasKey($payloadKey, $foreverSpy);

        $final = $foreverSpy[$payloadKey];
        self::assertSame(3, $final['version']);
        self::assertSame(2, $final['bump_counter']);
        self::assertSame('second', $final['last_reason']);
    }

    public function test_memory_store_increment_is_compatible_with_cache_version_authority(): void
    {
        $store = new MemoryStore();
        $authority = new CacheVersionAuthority($store, 'memory-atomic.prefix');

        for ($i = 0; $i < 5; $i++) {
            $authority->bump('authz.counter', 'tick-' . $i);
        }

        $envelope = $authority->readEnvelope('authz.counter');
        self::assertSame(6, $envelope['version']);
        self::assertSame(5, $envelope['bump_counter']);
        self::assertSame('tick-4', $envelope['last_reason']);
    }

    public function test_file_store_uses_atomic_increment_and_records_audit(): void
    {
        $store = new FileStore($this->storagePath, 'fs');
        self::assertInstanceOf(AtomicIncrementableStoreInterface::class, $store);

        $authority = new CacheVersionAuthority($store, 'fs-prefix');
        self::assertSame('v2', $authority->bump('authz.fs', 'seed'));
        self::assertSame('v3', $authority->bump('authz.fs', 'second'));

        $envelope = $authority->readEnvelope('authz.fs');
        self::assertSame(3, $envelope['version']);
        self::assertSame(2, $envelope['bump_counter']);
        self::assertSame('second', $envelope['last_reason']);
    }

    private function keyFor(string $prefix, string $scope): string
    {
        $suffix = sha1($scope);

        return $prefix . '.' . substr($suffix, 0, 2) . '.' . $suffix;
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
