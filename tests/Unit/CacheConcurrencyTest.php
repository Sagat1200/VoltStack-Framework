<?php

declare(strict_types=1);

namespace VoltStack\Test\Unit;

use PHPUnit\Framework\TestCase;
use Quantum\Cache\CasResult;
use Quantum\Cache\FileLockStore;
use Quantum\Cache\FileStore;
use Quantum\Cache\Lease;
use Quantum\Cache\LockManager;
use Quantum\Cache\MemoryLockStore;
use Quantum\Cache\MemoryStore;
use Quantum\Cache\NullStore;
use Quantum\Cache\Repository;
use Quantum\Cache\Contracts\ClockInterface;

final class CacheConcurrencyTest extends TestCase
{
    public function test_snapshot_returns_token_and_cas_applies_with_matching_token(): void
    {
        $store = new MemoryStore();
        $repository = new Repository($store);

        self::assertTrue($repository->put('counter', 1));

        $snapshot = $repository->snapshot('counter');

        self::assertNotNull($snapshot);
        self::assertSame(1, $snapshot->value);
        self::assertNotEmpty($snapshot->token);
        self::assertSame(1, $snapshot->incarnation);

        $result = $repository->compareAndSwap('counter', $snapshot->token, 2);

        self::assertTrue($result->isApplied());
        self::assertSame(2, $repository->get('counter'));

        // New token differs (incarnation advanced)
        self::assertNotSame($snapshot->token, $result->token);
    }

    public function test_cas_rejected_with_stale_token(): void
    {
        $store = new MemoryStore();
        $repository = new Repository($store);

        $repository->put('counter', 1);
        $snapshot = $repository->snapshot('counter');

        // mutate between snapshot and CAS
        $repository->put('counter', 99);

        $result = $repository->compareAndSwap('counter', $snapshot->token, 2);

        self::assertTrue($result->isRejected());
        self::assertSame('token_mismatch', $result->reason);
        self::assertSame(99, $repository->get('counter'));
    }

    public function test_cas_on_absent_key_is_rejected(): void
    {
        $store = new MemoryStore();
        $repository = new Repository($store);

        $result = $repository->compareAndSwap('missing', 'token', 'value');

        self::assertTrue($result->isRejected());
        self::assertSame('key_absent', $result->reason);
    }

    public function test_aba_protection_changes_token_after_delete_and_recreate(): void
    {
        $store = new MemoryStore();
        $repository = new Repository($store);

        $repository->put('aba', 'x');
        $snapshot = $repository->snapshot('aba');
        $originalToken = $snapshot->token;

        $repository->forget('aba');
        $repository->put('aba', 'x');

        $recreated = $repository->snapshot('aba');

        self::assertNotNull($recreated);
        self::assertNotSame($originalToken, $recreated->token);
        self::assertGreaterThan($snapshot->incarnation, $recreated->incarnation);
    }

    public function test_cas_not_supported_on_null_store(): void
    {
        $repository = new Repository(new NullStore());

        self::assertFalse($repository->supportsCas());
        self::assertNull($repository->snapshot('anything'));

        $result = $repository->compareAndSwap('anything', 'token', 'value');

        self::assertTrue($result->isRejected());
        self::assertSame('cas_not_supported', $result->reason);
    }

    public function test_file_store_cas_round_trip(): void
    {
        $path = $this->tempDir();
        $store = new FileStore($path, 'test');
        $repository = new Repository($store);

        try {
            $repository->put('file-counter', 10);
            $snapshot = $repository->snapshot('file-counter');

            self::assertNotNull($snapshot);

            $result = $repository->compareAndSwap('file-counter', $snapshot->token, 20);

            self::assertTrue($result->isApplied());
            self::assertSame(20, $repository->get('file-counter'));
        } finally {
            $this->deleteDirectory($path);
        }
    }

    public function test_lock_acquire_and_release_with_owner_verification(): void
    {
        $manager = new LockManager(new MemoryLockStore());

        $lease = $manager->acquire('resource', 5000);

        self::assertNotNull($lease);
        self::assertInstanceOf(Lease::class, $lease);
        self::assertTrue($manager->isHeld('resource'));

        // second acquirer gets null
        self::assertNull($manager->acquire('resource', 5000));

        self::assertTrue($lease->release());
        self::assertFalse($manager->isHeld('resource'));

        // release is idempotent
        self::assertFalse($lease->release());

        // now acquirable again
        self::assertNotNull($manager->acquire('resource', 5000));
    }

    public function test_lock_lease_renew_only_by_owner(): void
    {
        $manager = new LockManager(new MemoryLockStore());

        $lease = $manager->acquire('renewable', 1000);

        self::assertNotNull($lease);
        self::assertTrue($lease->renew(5000));

        // a different owner cannot acquire while held
        self::assertNull($manager->acquire('renewable', 5000));
    }

    public function test_lock_repository_integration(): void
    {
        $manager = new LockManager(new MemoryLockStore());
        $repository = new Repository(new MemoryStore(), 'scope', lockManager: $manager);

        self::assertTrue($repository->supportsLocks());

        $lease = $repository->lock('refresh', 5000);

        self::assertNotNull($lease);

        // lock name is scoped to the repository prefix
        self::assertNull($manager->acquire('scope:refresh', 5000));
        // unrelated names remain free
        self::assertNotNull($manager->acquire('scope:other', 5000));

        self::assertTrue($lease->release());
    }

    public function test_file_lock_store_cross_process_safety_with_mutex(): void
    {
        $path = $this->tempDir();
        $manager = new LockManager(new FileLockStore($path));

        try {
            $lease = $manager->acquire('shared', 5000);

            self::assertNotNull($lease);
            self::assertNull($manager->acquire('shared', 5000));
            self::assertTrue($lease->release());
            self::assertNotNull($manager->acquire('shared', 5000));
        } finally {
            $this->deleteDirectory($path);
        }
    }

    private function tempDir(): string
    {
        $path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'voltstack-cache-concurrency-' . bin2hex(random_bytes(6));

        if (! is_dir($path)) {
            mkdir($path, 0777, true);
        }

        return $path;
    }

    private function deleteDirectory(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }

        $items = scandir($path);

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $fullPath = $path . DIRECTORY_SEPARATOR . $item;

            if (is_dir($fullPath)) {
                $this->deleteDirectory($fullPath);
            } else {
                @unlink($fullPath);
            }
        }

        @rmdir($path);
    }
}
