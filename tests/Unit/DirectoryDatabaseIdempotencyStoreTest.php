<?php

declare(strict_types=1);

namespace VoltStack\Test\Unit;

use PHPUnit\Framework\TestCase;
use Quantum\Database\Operation\DatabaseIdempotencyRecord;
use Quantum\Database\Operation\Engine\DirectoryDatabaseIdempotencyStore;

final class DirectoryDatabaseIdempotencyStoreTest extends TestCase
{
    private string $basePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->basePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'voltstack-db-idempotency-directory-' . uniqid('', true);
        mkdir($this->basePath, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->deleteDirectory($this->basePath);

        parent::tearDown();
    }

    public function test_it_persists_and_blocks_duplicate_keys_in_directory_store(): void
    {
        $store = new DirectoryDatabaseIdempotencyStore($this->basePath . DIRECTORY_SEPARATOR . 'idempotency');
        $first = new DatabaseIdempotencyRecord(
            keyHash: hash('sha256', 'mutation-a'),
            operationFingerprint: 'plan-a',
            requestId: 'req-a',
            connectionName: 'primary',
            logicalTarget: 'users',
            createdAt: '2026-08-25T00:00:00+00:00',
            nodeId: 'node-a',
            status: 'pending',
        );
        $same = new DatabaseIdempotencyRecord(
            keyHash: hash('sha256', 'mutation-a'),
            operationFingerprint: 'plan-a',
            requestId: 'req-b',
            connectionName: 'primary',
            logicalTarget: 'users',
            createdAt: '2026-08-25T00:00:01+00:00',
            nodeId: 'node-b',
            status: 'pending',
        );
        $other = new DatabaseIdempotencyRecord(
            keyHash: hash('sha256', 'mutation-a'),
            operationFingerprint: 'plan-b',
            requestId: 'req-c',
            connectionName: 'primary',
            logicalTarget: 'users',
            createdAt: '2026-08-25T00:00:02+00:00',
            nodeId: 'node-c',
            status: 'pending',
        );

        $acquired = $store->acquire($first);
        $duplicate = $store->acquire($same);
        $conflict = $store->acquire($other);

        self::assertTrue($acquired->acquired);
        self::assertSame('acquired', $acquired->reason);
        self::assertFalse($duplicate->acquired);
        self::assertSame('duplicate', $duplicate->reason);
        self::assertFalse($conflict->acquired);
        self::assertSame('conflict', $conflict->reason);

        $store->complete($first);
        $store->release($first);

        $reacquired = $store->acquire($other);
        self::assertTrue($reacquired->acquired);
        self::assertSame('req-c', $store->latest()?->requestId);
        self::assertSame('req-c', $store->find($other->keyHash)?->requestId);
        self::assertCount(1, $store->recent(10));

        $aggregate = $store->aggregate(10);
        self::assertSame(1, $aggregate['records']);
        self::assertSame(1, $aggregate['statuses']['pending']);
    }

    public function test_it_reclaims_expired_pending_record(): void
    {
        $store = new DirectoryDatabaseIdempotencyStore($this->basePath . DIRECTORY_SEPARATOR . 'idempotency');
        $expired = new DatabaseIdempotencyRecord(
            keyHash: hash('sha256', 'mutation-expired'),
            operationFingerprint: 'plan-expired-a',
            requestId: 'req-expired-a',
            connectionName: 'primary',
            logicalTarget: 'users',
            createdAt: '2026-08-24T00:00:00+00:00',
            nodeId: 'node-a',
            status: 'pending',
            expiresAt: '2026-08-24T00:05:00+00:00',
        );
        $fresh = new DatabaseIdempotencyRecord(
            keyHash: hash('sha256', 'mutation-expired'),
            operationFingerprint: 'plan-expired-b',
            requestId: 'req-expired-b',
            connectionName: 'primary',
            logicalTarget: 'users',
            createdAt: '2026-08-25T00:00:00+00:00',
            nodeId: 'node-b',
            status: 'pending',
            expiresAt: '2099-08-25T00:05:00+00:00',
        );

        $store->acquire($expired);
        $reclaimed = $store->acquire($fresh);

        self::assertTrue($reclaimed->acquired);
        self::assertSame('reclaimed_expired', $reclaimed->reason);
        self::assertSame('req-expired-b', $store->find($fresh->keyHash)?->requestId);

        $aggregate = $store->aggregate(10);
        self::assertSame(0, $aggregate['expired_pending']);
    }

    public function test_it_recognizes_completed_record_as_confirmed_replay(): void
    {
        $store = new DirectoryDatabaseIdempotencyStore($this->basePath . DIRECTORY_SEPARATOR . 'idempotency');
        $completed = new DatabaseIdempotencyRecord(
            keyHash: hash('sha256', 'mutation-completed'),
            operationFingerprint: 'plan-completed-a',
            requestId: 'req-completed-a',
            connectionName: 'primary',
            logicalTarget: 'users',
            createdAt: '2026-08-25T00:00:00+00:00',
            nodeId: 'node-a',
            status: 'pending',
            expiresAt: '2099-08-25T00:05:00+00:00',
        );

        $store->acquire($completed);
        $store->complete($completed);

        $replay = $store->acquire(new DatabaseIdempotencyRecord(
            keyHash: hash('sha256', 'mutation-completed'),
            operationFingerprint: 'plan-completed-a',
            requestId: 'req-completed-b',
            connectionName: 'primary',
            logicalTarget: 'users',
            createdAt: '2026-08-25T00:01:00+00:00',
            nodeId: 'node-b',
            status: 'pending',
            expiresAt: '2099-08-25T00:06:00+00:00',
        ));

        self::assertFalse($replay->acquired);
        self::assertSame('replay', $replay->reason);
        self::assertSame('completed', $replay->record?->status);
        self::assertSame('req-completed-a', $replay->record?->requestId);
    }

    private function deleteDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $items = scandir($path);
        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if (in_array($item, ['.', '..'], true)) {
                continue;
            }

            $target = $path . DIRECTORY_SEPARATOR . $item;
            if (is_dir($target)) {
                $this->deleteDirectory($target);
                continue;
            }

            unlink($target);
        }

        rmdir($path);
    }
}