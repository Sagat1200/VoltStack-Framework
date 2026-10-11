<?php

declare(strict_types=1);

namespace VoltStack\Test\Unit;

use PHPUnit\Framework\TestCase;
use Quantum\Auth\Recovery\FileFederatedLinkNonceStore;
use Quantum\Auth\Recovery\InMemoryFederatedLinkNonceStore;

/**
 * Tests del almacén one-time de nonces de FederatedLink (Bloque 8).
 */
class FederatedLinkNonceStoreTest extends TestCase
{
    public function test_in_memory_consume_returns_true_once_and_false_on_replay(): void
    {
        $store = new InMemoryFederatedLinkNonceStore();

        self::assertFalse($store->isConsumed('nonce-abc-123'));
        self::assertTrue($store->consume('nonce-abc-123'), 'El primer consume debe tener éxito');
        self::assertTrue($store->isConsumed('nonce-abc-123'));
        self::assertFalse($store->consume('nonce-abc-123'), 'El segundo consume (replay) debe fallar');
        self::assertFalse($store->consume('nonce-abc-123'));
    }

    public function test_file_consume_returns_true_once_and_false_on_replay(): void
    {
        $filePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'voltstack-nonce-store-' . uniqid('', true) . '.jsonl';
        $store = new FileFederatedLinkNonceStore($filePath);

        self::assertFalse($store->isConsumed('nonce-abc-123'));
        self::assertTrue($store->consume('nonce-abc-123'), 'El primer consume debe tener éxito');
        self::assertTrue($store->isConsumed('nonce-abc-123'));
        self::assertFalse($store->consume('nonce-abc-123'), 'El segundo consume (replay) debe fallar');
        self::assertFalse($store->consume('nonce-abc-123'));

        @unlink($filePath);
    }

    public function test_in_memory_different_nonces_are_independent(): void
    {
        $store = new InMemoryFederatedLinkNonceStore();

        self::assertTrue($store->consume('nonce-one'));
        self::assertTrue($store->consume('nonce-two'), 'Nonce distinto debe poder consumirse');
        self::assertFalse($store->consume('nonce-one'), 'Nonce ya consumido debe fallar');
    }

    public function test_file_different_nonces_are_independent(): void
    {
        $filePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'voltstack-nonce-store-' . uniqid('', true) . '.jsonl';
        $store = new FileFederatedLinkNonceStore($filePath);

        self::assertTrue($store->consume('nonce-one'));
        self::assertTrue($store->consume('nonce-two'), 'Nonce distinto debe poder consumirse');
        self::assertFalse($store->consume('nonce-one'), 'Nonce ya consumido debe fallar');

        @unlink($filePath);
    }

    public function test_in_memory_purge_removes_consumed_nonces(): void
    {
        $store = new InMemoryFederatedLinkNonceStore();

        $store->consume('nonce-purge-1');
        $store->consume('nonce-purge-2');

        $purged = $store->purge(time() + 60);
        self::assertSame(2, $purged);

        self::assertFalse($store->isConsumed('nonce-purge-1'));
        self::assertFalse($store->isConsumed('nonce-purge-2'));
        self::assertTrue($store->consume('nonce-purge-1'), 'Nonce purgado puede volver a consumirse');
    }

    public function test_file_purge_removes_consumed_nonces(): void
    {
        $filePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'voltstack-nonce-store-' . uniqid('', true) . '.jsonl';
        $store = new FileFederatedLinkNonceStore($filePath);

        $store->consume('nonce-purge-1');
        $store->consume('nonce-purge-2');

        $purged = $store->purge(time() + 60);
        self::assertSame(2, $purged);

        self::assertFalse($store->isConsumed('nonce-purge-1'));
        self::assertFalse($store->isConsumed('nonce-purge-2'));
        self::assertTrue($store->consume('nonce-purge-1'), 'Nonce purgado puede volver a consumirse');

        @unlink($filePath);
    }

    public function test_in_memory_purge_with_past_threshold_removes_nothing(): void
    {
        $store = new InMemoryFederatedLinkNonceStore();
        $store->consume('nonce-future-1');

        $purged = $store->purge(time() - 3600);
        self::assertSame(0, $purged, 'Con umbral en el pasado no debe purgar nada');
        self::assertTrue($store->isConsumed('nonce-future-1'));
    }

    public function test_file_purge_with_past_threshold_removes_nothing(): void
    {
        $filePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'voltstack-nonce-store-' . uniqid('', true) . '.jsonl';
        $store = new FileFederatedLinkNonceStore($filePath);
        $store->consume('nonce-future-1');

        $purged = $store->purge(time() - 3600);
        self::assertSame(0, $purged, 'Con umbral en el pasado no debe purgar nada');
        self::assertTrue($store->isConsumed('nonce-future-1'));

        @unlink($filePath);
    }

    public function test_file_store_creates_directory_if_missing(): void
    {
        $baseDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'voltstack-nonce-dir-' . uniqid('', true);
        $filePath = $baseDir . DIRECTORY_SEPARATOR . 'nested' . DIRECTORY_SEPARATOR . 'nonces.jsonl';

        $store = new FileFederatedLinkNonceStore($filePath);
        self::assertTrue($store->consume('nonce-dir-create'));
        self::assertFileExists($filePath);

        $this->deleteTree($baseDir);
    }

    private function deleteTree(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }
        $items = scandir($path);
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $full = $path . DIRECTORY_SEPARATOR . $item;
            if (is_dir($full)) {
                $this->deleteTree($full);
            } else {
                @unlink($full);
            }
        }
        @rmdir($path);
    }
}
