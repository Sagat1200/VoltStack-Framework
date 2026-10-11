<?php

declare(strict_types=1);

namespace Quantum\Auth\Recovery;

use Quantum\Auth\Contracts\RecoveryTransactionStoreInterface;

/**
 * Driver durable JSONL para transacciones de recuperación.
 *
 * Cada línea del archivo JSONL representa una transacción serializada.
 * Las mutaciones (save/delete) reescriben el archivo completo bajo
 * `LOCK_EX` para garantizar consistencia entre procesos.
 *
 * Sigue los patrones VoltStack JSONL: `mkdir 0777 true`,
 * `JSON_THROW_ON_ERROR`, path resolution configurable.
 */
final class FileRecoveryTransactionStore implements RecoveryTransactionStoreInterface
{
    private string $storagePath;

    public function __construct(string $storagePath)
    {
        $this->storagePath = $storagePath;
    }

    public function find(string $tokenId): ?RecoveryTransaction
    {
        foreach ($this->readAll() as $transaction) {
            if ($transaction->tokenId === $tokenId) {
                return $transaction;
            }
        }

        return null;
    }

    public function save(RecoveryTransaction $transaction): void
    {
        $all = $this->readAll();
        $found = false;

        foreach ($all as $index => $existing) {
            if ($existing->tokenId === $transaction->tokenId) {
                $all[$index] = $transaction;
                $found = true;
                break;
            }
        }

        if (! $found) {
            $all[] = $transaction;
        }

        $this->writeAll($all);
    }

    public function delete(string $tokenId): void
    {
        $all = $this->readAll();
        $filtered = array_values(array_filter(
            $all,
            static fn (RecoveryTransaction $t): bool => $t->tokenId !== $tokenId,
        ));

        $this->writeAll($filtered);
    }

    public function deleteExpired(int $ttlSeconds, ?int $now = null): int
    {
        $now ??= time();
        $all = $this->readAll();
        $kept = [];
        $removed = 0;

        foreach ($all as $transaction) {
            if ($transaction->updatedAt > 0 && $now - $transaction->updatedAt > $ttlSeconds) {
                $removed++;
                continue;
            }
            $kept[] = $transaction;
        }

        if ($removed > 0) {
            $this->writeAll($kept);
        }

        return $removed;
    }

    /**
     * @return list<RecoveryTransaction>
     */
    private function readAll(): array
    {
        if (! file_exists($this->storagePath)) {
            return [];
        }

        $handle = fopen($this->storagePath, 'r');
        if ($handle === false) {
            return [];
        }

        $transactions = [];
        try {
            while (($line = fgets($handle)) !== false) {
                $line = trim($line);
                if ($line === '') {
                    continue;
                }

                try {
                    $data = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
                } catch (\Throwable) {
                    continue;
                }

                if (is_array($data)) {
                    $transactions[] = RecoveryTransaction::fromArray($data);
                }
            }
        } finally {
            fclose($handle);
        }

        return $transactions;
    }

    /**
     * @param list<RecoveryTransaction> $transactions
     */
    private function writeAll(array $transactions): void
    {
        $dir = dirname($this->storagePath);
        if (! is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        $handle = fopen($this->storagePath, 'c+');
        if ($handle === false) {
            throw new \RuntimeException('No se pudo abrir el archivo de transacciones de recuperación: ' . $this->storagePath);
        }

        try {
            if (! flock($handle, LOCK_EX)) {
                throw new \RuntimeException('No se pudo bloquear el archivo de transacciones de recuperación: ' . $this->storagePath);
            }

            ftruncate($handle, 0);
            rewind($handle);

            foreach ($transactions as $transaction) {
                $json = json_encode($transaction->toArray(), JSON_THROW_ON_ERROR);
                fwrite($handle, $json . PHP_EOL);
            }

            fflush($handle);
            flock($handle, LOCK_UN);
        } finally {
            fclose($handle);
        }
    }
}
