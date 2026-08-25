<?php

declare(strict_types=1);

namespace Quantum\Database\Operation\Engine;

use JsonException;
use Quantum\Database\Operation\Contracts\DatabaseIdempotencyStoreInterface;
use Quantum\Database\Operation\DatabaseIdempotencyAcquireResult;
use Quantum\Database\Operation\DatabaseIdempotencyAggregation;
use Quantum\Database\Operation\DatabaseIdempotencyRecord;

final class DirectoryDatabaseIdempotencyStore implements DatabaseIdempotencyStoreInterface
{
    public function __construct(
        private readonly string $directoryPath,
    ) {}

    public function acquire(DatabaseIdempotencyRecord $record): DatabaseIdempotencyAcquireResult
    {
        if (!is_dir($this->directoryPath)) {
            mkdir($this->directoryPath, 0777, true);
        }

        return $this->withKeyLock($record->keyHash, function () use ($record): DatabaseIdempotencyAcquireResult {
            $filePath = $this->filePathForHash($record->keyHash);
            if (!is_file($filePath)) {
                $this->writeRecord($filePath, $record);

                return DatabaseIdempotencyAcquireResult::acquired($record);
            }

            $existing = $this->readRecord($filePath);
            if (!$existing instanceof DatabaseIdempotencyRecord) {
                $this->writeRecord($filePath, $record);

                return DatabaseIdempotencyAcquireResult::acquired($record);
            }

            if ($existing->isExpired()) {
                $this->writeRecord($filePath, $record);

                return DatabaseIdempotencyAcquireResult::acquired($record, 'reclaimed_expired');
            }

            if ($existing->operationFingerprint === $record->operationFingerprint) {
                if ($existing->status === 'completed') {
                    return DatabaseIdempotencyAcquireResult::replay($existing);
                }

                return DatabaseIdempotencyAcquireResult::duplicate($existing);
            }

            return DatabaseIdempotencyAcquireResult::conflict($existing);
        });
    }

    public function complete(DatabaseIdempotencyRecord $record): void
    {
        $this->withKeyLock($record->keyHash, function () use ($record): void {
            $this->writeRecord($this->filePathForHash($record->keyHash), $record->withStatus('completed'));
        });
    }

    public function fail(DatabaseIdempotencyRecord $record): void
    {
        $this->withKeyLock($record->keyHash, function () use ($record): void {
            $this->writeRecord($this->filePathForHash($record->keyHash), $record->withStatus('failed'));
        });
    }

    public function release(DatabaseIdempotencyRecord $record): void
    {
        $this->withKeyLock($record->keyHash, function () use ($record): void {
            $filePath = $this->filePathForHash($record->keyHash);
            if (is_file($filePath)) {
                @unlink($filePath);
            }
        });
    }

    public function latest(): ?DatabaseIdempotencyRecord
    {
        $records = $this->recent(1);
        $latest = $records[array_key_last($records)] ?? null;

        return $latest instanceof DatabaseIdempotencyRecord ? $latest : null;
    }

    public function find(string $keyHash): ?DatabaseIdempotencyRecord
    {
        $filePath = $this->filePathForHash($keyHash);
        if (!is_file($filePath)) {
            return null;
        }

        return $this->readRecord($filePath);
    }

    public function recent(int $limit = 10): array
    {
        if (!is_dir($this->directoryPath)) {
            return [];
        }

        $files = glob($this->directoryPath . DIRECTORY_SEPARATOR . '*.json');
        if (!is_array($files) || $files === []) {
            return [];
        }

        $records = [];

        foreach ($files as $file) {
            if (!is_string($file) || !is_file($file)) {
                continue;
            }

            $record = $this->readRecord($file);
            if ($record instanceof DatabaseIdempotencyRecord) {
                $records[] = $record;
            }
        }

        usort($records, static fn(DatabaseIdempotencyRecord $left, DatabaseIdempotencyRecord $right): int => strcmp($left->createdAt, $right->createdAt));

        return array_values(array_slice($records, -max(1, $limit)));
    }

    public function aggregate(int $limit = 50): array
    {
        return DatabaseIdempotencyAggregation::aggregate($this->recent($limit));
    }

    private function filePathForHash(string $keyHash): string
    {
        $safe = preg_replace('/[^a-zA-Z0-9_.-]+/', '-', $keyHash);
        $safe = is_string($safe) && trim($safe) !== '' ? $safe : 'unknown-key';

        return $this->directoryPath . DIRECTORY_SEPARATOR . $safe . '.json';
    }

    private function lockPathForHash(string $keyHash): string
    {
        $safe = preg_replace('/[^a-zA-Z0-9_.-]+/', '-', $keyHash);
        $safe = is_string($safe) && trim($safe) !== '' ? $safe : 'unknown-key';

        return $this->directoryPath . DIRECTORY_SEPARATOR . $safe . '.lock';
    }

    private function readRecord(string $filePath): ?DatabaseIdempotencyRecord
    {
        $contents = file_get_contents($filePath);
        if (!is_string($contents) || trim($contents) === '') {
            return null;
        }

        try {
            $payload = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new \RuntimeException('Unable to decode database idempotency record.', 0, $exception);
        }

        return is_array($payload) ? DatabaseIdempotencyRecord::fromArray($payload) : null;
    }

    private function writeRecord(string $filePath, DatabaseIdempotencyRecord $record): void
    {
        try {
            $json = json_encode($record->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new \RuntimeException('Unable to encode database idempotency record.', 0, $exception);
        }

        file_put_contents($filePath, $json, LOCK_EX);
    }

    /**
     * @template T
     * @param callable(): T $callback
     * @return T
     */
    private function withKeyLock(string $keyHash, callable $callback): mixed
    {
        if (!is_dir($this->directoryPath)) {
            mkdir($this->directoryPath, 0777, true);
        }

        $lockPath = $this->lockPathForHash($keyHash);
        $handle = fopen($lockPath, 'c+');
        if ($handle === false) {
            throw new \RuntimeException('Unable to open database idempotency lock file.');
        }

        try {
            if (!flock($handle, LOCK_EX)) {
                throw new \RuntimeException('Unable to acquire database idempotency lock.');
            }

            try {
                return $callback();
            } finally {
                flock($handle, LOCK_UN);
            }
        } finally {
            fclose($handle);
        }
    }
}
